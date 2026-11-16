<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\AdminSaasService;
use PosAdmin\Service\SaasCapabilityService;
use PosAdmin\Service\CommercialComplementService;

final class AdminEmpresaPermisoController
{
    private const MAX_SAVE_ITEMS = 500;

    private const PERMISSION_EDITORIAL_DEFAULTS = [
        'grupo_ui' => 'OTROS',
        'grupo_ui_nombre' => 'Otros permisos',
        'orden_ui' => 1000,
        'visible_ui' => true,
    ];

    /** GET /admin/empresas/{id}/permisos */
    public function list(int $idEmpresa): void
    {
        $pdo = Database::getConnection();

        // valida empresa
        $chk = $pdo->prepare("SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :id");
        $chk->execute([':id' => $idEmpresa]);
        if (!$chk->fetchColumn()) {
            Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
        }

        // catálogo + enabled por empresa (default true si no hay fila en admin)
        $editorialColumns = $this->permissionEditorialColumns($pdo);
        $editorialSelect = implode(",\n            ", self::permissionEditorialSelectExpressions($editorialColumns));
        $st = $pdo->prepare("
          SELECT
            p.id_permiso,
            p.codigo,
            p.descripcion,
            {$editorialSelect},
            COALESCE(ep.enabled, true) AS enabled
          FROM pos_saas.permiso p
          LEFT JOIN admin.empresa_permiso ep
            ON ep.id_empresa = :eid AND ep.id_permiso = p.id_permiso
          ORDER BY " . self::permissionCatalogOrderBy() . "
        ");
        $st->execute([':eid' => $idEmpresa]);
        $items = $st->fetchAll(\PDO::FETCH_ASSOC);
        $items = array_map([self::class, 'normalizePermissionCatalogItem'], $items ?: []);

        Response::json([
            'ok' => true,
            'id_empresa' => $idEmpresa,
            'deprecated' => (new CommercialComplementService())->plansMode(),
            'items' => $items,
        ]);
    }

    /** PUT /admin/empresas/{id}/permisos  body: { items: [{id_permiso, enabled}] } */
    public function save(int $idEmpresa, array $body): void
    {
        if ((new CommercialComplementService())->plansMode()) {
            Response::json([
                'error' => 'EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA',
                'message' => 'En modo planes, los permisos se administran desde plan, complementos o excepciones internas.',
            ], 409);
        }
        $actorId = (int)($_REQUEST['adminId'] ?? 0);
        $actorEmail = (string)($_REQUEST['adminEmail'] ?? '');

        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            Response::json(['error' => 'VALIDATION', 'message' => 'items debe ser un array'], 422);
        }
        if (count($items) > self::MAX_SAVE_ITEMS) {
            Response::json(['error' => 'VALIDATION', 'message' => 'items supera el limite permitido'], 422);
        }

        // normaliza payload
        $norm = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $idPerm = (int)($it['id_permiso'] ?? 0);
            if ($idPerm <= 0) continue;

            if (!array_key_exists('enabled', $it)) {
                Response::json(['error' => 'VALIDATION', 'message' => "enabled requerido para id_permiso: $idPerm"], 422);
            }
            $enabledRaw = $it['enabled'];
            $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                Response::json(['error' => 'VALIDATION', 'message' => "enabled invalido para id_permiso: $idPerm"], 422);
            }

            $norm[$idPerm] = (bool)$enabled;
        }

        if (!$norm) {
            Response::json(['error' => 'VALIDATION', 'message' => 'items vacío o inválido'], 422);
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            // valida empresa
            $chk = $pdo->prepare("SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :id");
            $chk->execute([':id' => $idEmpresa]);
            if (!$chk->fetchColumn()) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
            }

            // valida que los permisos existan
            $ids = array_values(array_map('intval', array_keys($norm)));
            $idsPgArray = self::postgresBigIntArray($ids);

            $validSt = $pdo->prepare("
              SELECT id_permiso, codigo
              FROM pos_saas.permiso
              WHERE id_permiso = ANY(CAST(:ids AS bigint[]))
            ");
            $validSt->execute([':ids' => $idsPgArray]);
            $validPermissions = $validSt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $validIds = array_map('intval', array_column($validPermissions, 'id_permiso'));

            $validSet = array_flip($validIds);
            foreach ($ids as $pid) {
                if (!isset($validSet[$pid])) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "id_permiso inválido: $pid"], 422);
                }
            }

            foreach ($validPermissions as $permission) {
                $idPermiso = (int)$permission['id_permiso'];
                if (
                    ($norm[$idPermiso] ?? true) === false
                    && AdminSaasService::isCoreBasePermission((string)$permission['codigo'])
                ) {
                    $pdo->rollBack();
                    Response::json([
                        'error' => 'PERMISO_BASE_NO_DESHABILITABLE',
                        'message' => 'Los permisos base operativos no pueden deshabilitarse por empresa.',
                    ], 409);
                }
            }

            $capabilities = new SaasCapabilityService();
            $enabledPermissionIds = array_keys(array_filter($norm, static fn (bool $enabled): bool => $enabled));
            $capabilities->assertPermissionsCanBeEnabled($pdo, $idEmpresa, $enabledPermissionIds);

            // BEFORE snapshot
            $beforeSt = $pdo->prepare("
              SELECT id_permiso, enabled
              FROM admin.empresa_permiso
              WHERE id_empresa = :empresa
                AND id_permiso = ANY(CAST(:ids AS bigint[]))
              ORDER BY id_permiso
            ");
            $beforeSt->execute([':empresa' => $idEmpresa, ':ids' => $idsPgArray]);
            $before = $beforeSt->fetchAll(\PDO::FETCH_ASSOC);

            // Un solo round trip aun cuando la interfaz envie el catalogo completo.
            $up = $pdo->prepare("
              INSERT INTO admin.empresa_permiso (id_empresa, id_permiso, enabled)
              SELECT :eid, item.id_permiso, item.enabled
              FROM jsonb_to_recordset(CAST(:items AS jsonb))
                AS item(id_permiso bigint, enabled boolean)
              ON CONFLICT (id_empresa, id_permiso)
              DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
            ");
            $batchItems = array_map(
                static fn (int $idPermiso, bool $enabled): array => [
                    'id_permiso' => $idPermiso,
                    'enabled' => $enabled,
                ],
                array_keys($norm),
                array_values($norm)
            );
            $up->execute([
                ':eid' => $idEmpresa,
                ':items' => json_encode($batchItems, JSON_THROW_ON_ERROR),
            ]);

            // AFTER snapshot
            $afterSt = $pdo->prepare("
              SELECT id_permiso, enabled
              FROM admin.empresa_permiso
              WHERE id_empresa = :empresa
                AND id_permiso = ANY(CAST(:ids AS bigint[]))
              ORDER BY id_permiso
            ");
            $afterSt->execute([':empresa' => $idEmpresa, ':ids' => $idsPgArray]);
            $after = $afterSt->fetchAll(\PDO::FETCH_ASSOC);

            // AUDIT
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $audit = $pdo->prepare("
              INSERT INTO admin.audit_log
                (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
              VALUES
                (:aid, :aem, 'EMPRESA_PERMISOS_SAVE', 'empresa', :tid, :before::jsonb, :after::jsonb, :ip, :ua)
            ");
            $audit->execute([
                ':aid' => $actorId ?: null,
                ':aem' => $actorEmail ?: null,
                ':tid' => $idEmpresa,
                ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                ':after'  => json_encode($after, JSON_UNESCAPED_UNICODE),
                ':ip' => $ip,
                ':ua' => $ua,
            ]);

            $sync = new AdminTenantSyncService();
            $sync->enqueueBusinessConfig($pdo, $idEmpresa);

            $pdo->commit();
            Response::json([
                'ok' => true,
                'id_empresa' => $idEmpresa,
                'saved' => count($norm),
                // El cambio ya esta confirmado en control. La sincronizacion
                // del tenant se procesa por outbox para no agotar el timeout HTTP.
                'sync' => [
                    'estado' => 'PENDIENTE',
                    'id_empresa' => $idEmpresa,
                    'detalle' => 'SINCRONIZACION_EN_COLA',
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $payload = ['error' => 'SERVER_ERROR'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }

    /** @return array{grupo_ui:string,grupo_ui_nombre:string,orden_ui:int,visible_ui:bool} */
    public static function permissionEditorialDefaults(): array
    {
        return self::PERMISSION_EDITORIAL_DEFAULTS;
    }

    /** @return array<string, bool> */
    private function permissionEditorialColumns(\PDO $pdo): array
    {
        try {
            $st = $pdo->prepare("
              SELECT column_name
              FROM information_schema.columns
              WHERE table_schema = 'pos_saas'
                AND table_name = 'permiso'
                AND column_name IN ('grupo_ui', 'grupo_ui_nombre', 'orden_ui', 'visible_ui')
            ");
            $st->execute();
            return self::normalizePermissionEditorialColumns($st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable) {
            return self::normalizePermissionEditorialColumns([]);
        }
    }

    /** @param list<string> $columns @return array<string, bool> */
    private static function normalizePermissionEditorialColumns(array $columns): array
    {
        $set = array_fill_keys(array_map('strval', $columns), true);
        return [
            'grupo_ui' => isset($set['grupo_ui']),
            'grupo_ui_nombre' => isset($set['grupo_ui_nombre']),
            'orden_ui' => isset($set['orden_ui']),
            'visible_ui' => isset($set['visible_ui']),
        ];
    }

    /** @param array<string, bool> $columns @return list<string> */
    private static function permissionEditorialSelectExpressions(array $columns): array
    {
        return [
            ($columns['grupo_ui'] ?? false)
                ? "COALESCE(NULLIF(p.grupo_ui, ''), 'OTROS') AS grupo_ui"
                : "'OTROS' AS grupo_ui",
            ($columns['grupo_ui_nombre'] ?? false)
                ? "COALESCE(NULLIF(p.grupo_ui_nombre, ''), 'Otros permisos') AS grupo_ui_nombre"
                : "'Otros permisos' AS grupo_ui_nombre",
            ($columns['orden_ui'] ?? false)
                ? 'COALESCE(p.orden_ui, 1000) AS orden_ui'
                : '1000 AS orden_ui',
            ($columns['visible_ui'] ?? false)
                ? 'COALESCE(p.visible_ui, true) AS visible_ui'
                : 'true AS visible_ui',
        ];
    }

    private static function permissionCatalogOrderBy(): string
    {
        return 'orden_ui ASC, p.codigo ASC';
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function normalizePermissionCatalogItem(array $item): array
    {
        $defaults = self::PERMISSION_EDITORIAL_DEFAULTS;
        $item['grupo_ui'] = trim((string)($item['grupo_ui'] ?? '')) ?: $defaults['grupo_ui'];
        $item['grupo_ui_nombre'] = trim((string)($item['grupo_ui_nombre'] ?? '')) ?: $defaults['grupo_ui_nombre'];
        $item['orden_ui'] = (int)($item['orden_ui'] ?? $defaults['orden_ui']);
        $item['visible_ui'] = self::boolValue($item['visible_ui'] ?? $defaults['visible_ui']);
        return $item;
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        $text = strtolower(trim((string)$value));
        return in_array($text, ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }

    /** @param list<int> $ids */
    private static function postgresBigIntArray(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }
}

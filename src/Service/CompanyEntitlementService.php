<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

/**
 * Materializa el techo comercial de una empresa y administra las excepciones
 * internas auditables. Las capacidades SaaS conservan su servicio separado.
 */
final class CompanyEntitlementService
{
    private const CORE_BASE_PERMISSION_CODES = [
        'TESORERIA__VER',
        'TESORERIA__CONFIGURAR',
        'TESORERIA__TRASLADAR',
        'TESORERIA__AJUSTAR',
    ];

    /** @return array{modulos:int,permisos:int} */
    public function materializePlan(PDO $pdo, int $idEmpresa, int $idPlan): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $this->assertPlanExists($pdo, $idPlan);
        $resources = $this->commercialResources($pdo, $idEmpresa, $idPlan);

        $modules = $pdo->prepare('
            INSERT INTO admin.empresa_modulo (
                id_empresa, id_modulo, enabled, created_at, updated_at
            )
            SELECT
                :empresa,
                m.id_modulo,
                CASE
                    WHEN ex.id_excepcion IS NOT NULL THEN ex.enabled
                    ELSE COALESCE(spm.enabled, false)
                         OR m.id_modulo = ANY(CAST(:complement_modules AS bigint[]))
                END,
                now(),
                now()
            FROM pos_saas.modulo m
            LEFT JOIN admin.saas_plan_modulo spm
              ON spm.id_plan = :plan
             AND spm.id_modulo = m.id_modulo
            LEFT JOIN admin.empresa_entitlement_excepcion ex
              ON ex.id_empresa = :empresa AND ex.id_modulo = m.id_modulo
            WHERE COALESCE(m.estado, 1) = 1
            ON CONFLICT (id_empresa, id_modulo) DO UPDATE
            SET enabled = EXCLUDED.enabled,
                updated_at = now()
        ');
        $modules->execute([
            ':empresa' => $idEmpresa,
            ':plan' => $idPlan,
            ':complement_modules' => self::postgresBigIntArray($resources['modulos']),
        ]);

        $permissions = $pdo->prepare('
            INSERT INTO admin.empresa_permiso (
                id_empresa, id_permiso, enabled, created_at, updated_at
            )
            SELECT
                :empresa,
                p.id_permiso,
                CASE
                    WHEN p.codigo IN (
                        \'' . implode("', '", self::CORE_BASE_PERMISSION_CODES) . '\'
                    ) THEN true
                    WHEN ex.id_excepcion IS NOT NULL THEN ex.enabled
                    ELSE COALESCE(spp.enabled, false)
                         OR p.id_permiso = ANY(CAST(:complement_permissions AS bigint[]))
                END,
                now(),
                now()
            FROM pos_saas.permiso p
            LEFT JOIN admin.saas_plan_permiso spp
              ON spp.id_plan = :plan
             AND spp.id_permiso = p.id_permiso
            LEFT JOIN admin.empresa_entitlement_excepcion ex
              ON ex.id_empresa = :empresa AND ex.id_permiso = p.id_permiso
            ON CONFLICT (id_empresa, id_permiso) DO UPDATE
            SET enabled = EXCLUDED.enabled,
                updated_at = now()
        ');
        $permissions->execute([
            ':empresa' => $idEmpresa,
            ':plan' => $idPlan,
            ':complement_permissions' => self::postgresBigIntArray($resources['permisos']),
        ]);

        return [
            'modulos' => $modules->rowCount(),
            'permisos' => $permissions->rowCount(),
        ];
    }

    /** @return array{modulos:int,permisos:int} */
    public function materializeCurrent(PDO $pdo, int $idEmpresa): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $subscription = $this->subscription($pdo, $idEmpresa);
        if ($subscription === null) {
            throw new \RuntimeException('SUSCRIPCION_NO_ENCONTRADA');
        }
        return $this->materializePlan($pdo, $idEmpresa, (int)$subscription['id_plan']);
    }

    /** @return array<string,mixed> */
    public function effectiveAccesses(PDO $pdo, int $idEmpresa, SaasCapabilityService $capabilities): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $this->assertExceptionStorage($pdo);

        $subscription = $this->subscription($pdo, $idEmpresa);
        $idPlan = (int)($subscription['id_plan'] ?? 0);
        $resources = $this->commercialResources($pdo, $idEmpresa, $idPlan);
        $modules = $this->effectiveModules($pdo, $idEmpresa, $idPlan, $resources['modulos']);
        $permissionAccess = $this->permissionAccesses($pdo, $idEmpresa, $idPlan, $resources['permisos']);
        $permissions = $permissionAccess['efectivos'];

        return [
            'suscripcion' => $subscription,
            'modo_entitlements' => (new CommercialComplementService())->plansMode() ? 'plans' : 'legacy',
            'modulos' => $modules,
            'permisos' => $permissions,
            'permisos_no_disponibles' => $permissionAccess['no_disponibles'],
            'modulos_agrupados' => $this->groupByOrigin($modules),
            'permisos_agrupados' => $this->groupByOrigin($permissions),
            'complementos' => (new CommercialComplementService())->hasStorage($pdo)
                ? (new CommercialComplementService())->companyActivations($pdo, $idEmpresa)
                : [],
            'excepciones' => $this->listExceptions($pdo, $idEmpresa),
            'capacidades_efectivas' => $capabilities->companyCapabilities($pdo, $idEmpresa),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listExceptions(PDO $pdo, int $idEmpresa): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $this->assertExceptionStorage($pdo);

        $st = $pdo->prepare('
            SELECT
                e.id_excepcion,
                e.id_empresa,
                e.id_modulo,
                e.id_permiso,
                e.enabled,
                e.motivo,
                e.actor_id,
                sa.email AS actor_email,
                e.created_at,
                e.updated_at,
                m.ruta AS modulo_ruta,
                m.nombre AS modulo_nombre,
                p.codigo AS permiso_codigo,
                p.descripcion AS permiso_nombre
            FROM admin.empresa_entitlement_excepcion e
            LEFT JOIN admin.superadmin_user sa ON sa.id_superadmin = e.actor_id
            LEFT JOIN pos_saas.modulo m ON m.id_modulo = e.id_modulo
            LEFT JOIN pos_saas.permiso p ON p.id_permiso = e.id_permiso
            WHERE e.id_empresa = :empresa
            ORDER BY e.updated_at DESC, e.id_excepcion DESC
        ');
        $st->execute([':empresa' => $idEmpresa]);

        return array_map([$this, 'normalizeException'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function saveException(PDO $pdo, int $idEmpresa, array $body, int $actorId, string $actorEmail): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $this->assertExceptionStorage($pdo);
        $this->assertAdminActorStorage($pdo);
        if ($actorId <= 0) {
            throw new \RuntimeException('ACTOR_ADMIN_REQUERIDO');
        }

        $idModulo = $this->positiveId($body['id_modulo'] ?? null);
        $idPermiso = $this->positiveId($body['id_permiso'] ?? null);
        if (($idModulo > 0) === ($idPermiso > 0)) {
            throw new \InvalidArgumentException('EXCEPCION_OBJETIVO_UNICO_REQUERIDO');
        }
        if (!array_key_exists('enabled', $body)) {
            throw new \InvalidArgumentException('EXCEPCION_ENABLED_REQUERIDO');
        }
        $enabled = filter_var($body['enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new \InvalidArgumentException('EXCEPCION_ENABLED_INVALIDO');
        }
        $motivo = trim((string)($body['motivo'] ?? ''));
        if (mb_strlen($motivo) < 10) {
            throw new \InvalidArgumentException('EXCEPCION_MOTIVO_MINIMO_10');
        }

        if ($idModulo > 0) {
            $this->assertActiveModule($pdo, $idModulo);
        } else {
            $this->assertPermissionExists($pdo, $idPermiso);
        }

        $complements = new CommercialComplementService();
        if ($complements->plansMode()) {
            if (!$complements->hasStorage($pdo)) {
                throw new \RuntimeException('COMPLEMENTOS_SCHEMA_MISSING');
            }
            $allowed = $idModulo > 0
                ? $complements->isInternalModule($pdo, $idModulo)
                : $complements->isInternalPermission($pdo, $idPermiso);
            if (!$allowed) {
                throw new \InvalidArgumentException('EXCEPCION_INTERNA_RECURSO_NO_PERMITIDO');
            }
        }

        if ($enabled) {
            $this->assertSaasCapabilityCeiling($pdo, $idEmpresa, $idModulo, $idPermiso);
        }

        $before = $this->exceptionByTarget($pdo, $idEmpresa, $idModulo, $idPermiso);
        $upsert = $pdo->prepare('
            INSERT INTO admin.empresa_entitlement_excepcion (
                id_empresa, id_modulo, id_permiso, enabled, motivo, actor_id, created_at, updated_at
            )
            VALUES (
                :empresa, :modulo, :permiso, CAST(:enabled AS boolean), :motivo, :actor, now(), now()
            )
            ON CONFLICT ' . ($idModulo > 0 ? '(id_empresa, id_modulo) WHERE id_modulo IS NOT NULL' : '(id_empresa, id_permiso) WHERE id_permiso IS NOT NULL') . ' DO UPDATE
            SET enabled = EXCLUDED.enabled,
                motivo = EXCLUDED.motivo,
                actor_id = EXCLUDED.actor_id,
                updated_at = now()
            RETURNING id_excepcion
        ');
        $upsert->execute([
            ':empresa' => $idEmpresa,
            ':modulo' => $idModulo > 0 ? $idModulo : null,
            ':permiso' => $idPermiso > 0 ? $idPermiso : null,
            ':enabled' => $enabled ? 'true' : 'false',
            ':motivo' => $motivo,
            ':actor' => $actorId,
        ]);
        $idException = (int)$upsert->fetchColumn();
        $after = $this->exceptionById($pdo, $idEmpresa, $idException);
        if ($after === null) {
            throw new \RuntimeException('EXCEPCION_NO_ENCONTRADA');
        }

        $this->audit($pdo, $actorId, $actorEmail, 'EMPRESA_ENTITLEMENT_EXCEPCION_SAVE', $idEmpresa, $before, $after);
        return $after;
    }

    /** @return array<string,mixed> */
    public function deleteException(PDO $pdo, int $idEmpresa, int $idException, int $actorId, string $actorEmail): array
    {
        $this->assertCompanyExists($pdo, $idEmpresa);
        $this->assertExceptionStorage($pdo);
        $this->assertAdminActorStorage($pdo);
        if ($actorId <= 0) {
            throw new \RuntimeException('ACTOR_ADMIN_REQUERIDO');
        }

        $before = $this->exceptionById($pdo, $idEmpresa, $idException);
        if ($before === null) {
            throw new \RuntimeException('EXCEPCION_NO_ENCONTRADA');
        }

        $delete = $pdo->prepare('
            DELETE FROM admin.empresa_entitlement_excepcion
            WHERE id_empresa = :empresa AND id_excepcion = :id
        ');
        $delete->execute([':empresa' => $idEmpresa, ':id' => $idException]);
        $this->audit($pdo, $actorId, $actorEmail, 'EMPRESA_ENTITLEMENT_EXCEPCION_DELETE', $idEmpresa, $before, null);

        return ['id_excepcion' => $idException, 'eliminada' => true];
    }

    /** @return array{enabled:bool,origen:string} */
    public static function resolveEffective(bool $planEnabled, ?bool $materializedEnabled, ?bool $exceptionEnabled): array
    {
        if ($exceptionEnabled !== null) {
            return ['enabled' => $exceptionEnabled, 'origen' => 'EXCEPCION_INTERNA'];
        }
        if ($materializedEnabled !== null) {
            return ['enabled' => $materializedEnabled, 'origen' => 'PLAN'];
        }
        return ['enabled' => $planEnabled, 'origen' => 'PLAN'];
    }

    /** @return list<array<string,mixed>> */
    private function effectiveModules(PDO $pdo, int $idEmpresa, int $idPlan, array $complementModules): array
    {
        $st = $pdo->prepare('
            SELECT m.id_modulo, m.ruta, m.nombre,
                   spm.enabled AS plan_enabled,
                   ex.enabled AS exception_enabled
            FROM pos_saas.modulo m
            LEFT JOIN admin.saas_plan_modulo spm
              ON spm.id_plan = :plan AND spm.id_modulo = m.id_modulo
            LEFT JOIN admin.empresa_entitlement_excepcion ex
              ON ex.id_empresa = :empresa AND ex.id_modulo = m.id_modulo
            WHERE COALESCE(m.estado, 1) = 1
            ORDER BY m.orden ASC, m.id_modulo ASC
        ');
        $st->execute([':empresa' => $idEmpresa, ':plan' => $idPlan]);

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $planEnabled = $this->toBool($row['plan_enabled'] ?? false);
            $fromComplement = in_array((int)$row['id_modulo'], $complementModules, true);
            $baseEnabled = $planEnabled || $fromComplement;
            $exception = $this->nullableBool($row['exception_enabled'] ?? null);
            $resolved = $exception === null
                ? ['enabled' => $baseEnabled, 'origen' => (!$planEnabled && $fromComplement ? 'COMPLEMENTO' : 'PLAN')]
                : self::resolveEffective($baseEnabled, null, $exception);
            if (!$resolved['enabled']) {
                continue;
            }
            $items[] = [
                'id_modulo' => (int)$row['id_modulo'],
                'ruta' => (string)$row['ruta'],
                'nombre' => (string)$row['nombre'],
                'origen' => $resolved['origen'],
            ];
        }
        return $items;
    }

    /**
     * @param list<int> $activeComplementPermissions
     * @return array{efectivos:list<array<string,mixed>>,no_disponibles:list<array<string,mixed>>}
     */
    private function permissionAccesses(PDO $pdo, int $idEmpresa, int $idPlan, array $activeComplementPermissions): array
    {
        $editorial = $this->permissionEditorialColumns($pdo);
        $metadata = $this->permissionEditorialSelectExpressions($editorial);
        $st = $pdo->prepare('
            SELECT p.id_permiso, p.codigo, p.descripcion,
                   ' . implode(",\n                   ", $metadata) . ',
                   (
                       SELECT m.id_modulo
                       FROM pos_saas.modulo m
                       WHERE m.id_permiso_gate = p.id_permiso
                         AND COALESCE(m.estado, 1) = 1
                       ORDER BY m.orden ASC, m.id_modulo ASC
                       LIMIT 1
                   ) AS id_modulo_asociado,
                   (
                       SELECT m.ruta
                       FROM pos_saas.modulo m
                       WHERE m.id_permiso_gate = p.id_permiso
                         AND COALESCE(m.estado, 1) = 1
                       ORDER BY m.orden ASC, m.id_modulo ASC
                       LIMIT 1
                   ) AS ruta_modulo_asociado,
                   spp.enabled AS plan_enabled,
                   ex.enabled AS exception_enabled
            FROM pos_saas.permiso p
            LEFT JOIN admin.saas_plan_permiso spp
              ON spp.id_plan = :plan AND spp.id_permiso = p.id_permiso
            LEFT JOIN admin.empresa_entitlement_excepcion ex
              ON ex.id_empresa = :empresa AND ex.id_permiso = p.id_permiso
            ORDER BY orden_ui ASC, p.codigo ASC
        ');
        $st->execute([':empresa' => $idEmpresa, ':plan' => $idPlan]);

        $effective = [];
        $unavailable = [];
        $commercialComplementPermissions = $this->commercialComplementPermissionIds($pdo);
        $compatibleComplementPermissions = $this->compatibleComplementPermissions($pdo, $idPlan);
        $hasCommercialPlan = $idPlan > 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!$this->toBool($row['visible_ui'] ?? true)) {
                continue;
            }
            $planEnabled = $this->toBool($row['plan_enabled'] ?? false);
            $coreBase = in_array((string)$row['codigo'], self::CORE_BASE_PERMISSION_CODES, true);
            $fromComplement = in_array((int)$row['id_permiso'], $activeComplementPermissions, true);
            $baseEnabled = $coreBase || $planEnabled || $fromComplement;
            $exception = $this->nullableBool($row['exception_enabled'] ?? null);
            $resolved = $exception === null
                ? ['enabled' => $baseEnabled, 'origen' => (!$coreBase && !$planEnabled && $fromComplement ? 'COMPLEMENTO' : 'PLAN')]
                : self::resolveEffective($baseEnabled, null, $exception);

            $item = [
                'id_permiso' => (int)$row['id_permiso'],
                'codigo' => (string)$row['codigo'],
                'nombre' => (string)$row['descripcion'],
                'descripcion' => (string)$row['descripcion'],
                'grupo_ui' => (string)($row['grupo_ui'] ?? 'OTROS'),
                'grupo_ui_nombre' => (string)($row['grupo_ui_nombre'] ?? 'Otros permisos'),
                'orden_ui' => (int)($row['orden_ui'] ?? 1000),
                'id_modulo_asociado' => $row['id_modulo_asociado'] === null ? null : (int)$row['id_modulo_asociado'],
                'ruta_modulo_asociado' => $row['ruta_modulo_asociado'] ?? null,
            ];

            if ($resolved['enabled']) {
                $item['origen'] = $resolved['origen'];
                $effective[] = $item;
                continue;
            }

            $item['motivo'] = self::unavailablePermissionReason(
                $hasCommercialPlan,
                $planEnabled,
                $coreBase,
                in_array((int)$row['id_permiso'], $compatibleComplementPermissions, true),
                in_array((int)$row['id_permiso'], $commercialComplementPermissions, true),
                $exception
            );
            $unavailable[] = $item;
        }
        return ['efectivos' => $effective, 'no_disponibles' => $unavailable];
    }

    /**
     * The order here is part of the public API: an explicit restrictive
     * exception always explains absence before plan or complement rules.
     */
    public static function unavailablePermissionReason(
        bool $hasCommercialPlan,
        bool $planEnabled,
        bool $coreBase,
        bool $hasCompatibleInactiveComplement,
        bool $hasCommercialComplement,
        ?bool $exceptionEnabled
    ): string {
        if ($exceptionEnabled === false) {
            return 'RESTRINGIDO_EXCEPCION';
        }
        if ($hasCompatibleInactiveComplement && !$coreBase && !$planEnabled) {
            return 'REQUIERE_COMPLEMENTO';
        }
        if (!$hasCommercialPlan) {
            return 'NO_DISPONIBLE';
        }
        if ($hasCommercialComplement) {
            return 'NO_DISPONIBLE';
        }
        return 'NO_INCLUIDO_PLAN';
    }

    /** @return array<string,mixed>|null */
    private function subscription(PDO $pdo, int $idEmpresa): ?array
    {
        $st = $pdo->prepare('
            SELECT s.id_suscripcion, s.id_plan, s.estado, s.ciclo,
                   p.codigo AS plan_codigo, p.nombre AS plan_nombre,
                   s.updated_at
            FROM admin.saas_suscripcion s
            JOIN admin.saas_plan p ON p.id_plan = s.id_plan
            WHERE s.id_empresa = :empresa
              AND s.estado IN (\'PRUEBA\', \'ACTIVA\', \'POR_VENCER\', \'VENCIDA\')
            ORDER BY s.updated_at DESC, s.id_suscripcion DESC
            LIMIT 1
        ');
        $st->execute([':empresa' => $idEmpresa]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['id_suscripcion'] = (int)$row['id_suscripcion'];
        $row['id_plan'] = (int)$row['id_plan'];
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function exceptionByTarget(PDO $pdo, int $idEmpresa, int $idModulo, int $idPermiso): ?array
    {
        $column = $idModulo > 0 ? 'id_modulo' : 'id_permiso';
        $id = $idModulo > 0 ? $idModulo : $idPermiso;
        $st = $pdo->prepare("SELECT id_excepcion FROM admin.empresa_entitlement_excepcion WHERE id_empresa = :empresa AND {$column} = :target LIMIT 1");
        $st->execute([':empresa' => $idEmpresa, ':target' => $id]);
        $idException = (int)($st->fetchColumn() ?: 0);
        return $idException > 0 ? $this->exceptionById($pdo, $idEmpresa, $idException) : null;
    }

    /** @return array<string,mixed>|null */
    private function exceptionById(PDO $pdo, int $idEmpresa, int $idException): ?array
    {
        foreach ($this->listExceptions($pdo, $idEmpresa) as $item) {
            if ((int)$item['id_excepcion'] === $idException) {
                return $item;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeException(array $row): array
    {
        $enabled = $this->toBool($row['enabled'] ?? false);
        $isModule = !empty($row['id_modulo']);
        return [
            'id_excepcion' => (int)$row['id_excepcion'],
            'id_empresa' => (int)$row['id_empresa'],
            'tipo' => $isModule ? 'MODULO' : 'PERMISO',
            'id_modulo' => $isModule ? (int)$row['id_modulo'] : null,
            'id_permiso' => !$isModule ? (int)$row['id_permiso'] : null,
            'recurso' => $isModule
                ? ['ruta' => (string)($row['modulo_ruta'] ?? ''), 'nombre' => (string)($row['modulo_nombre'] ?? '')]
                : ['codigo' => (string)($row['permiso_codigo'] ?? ''), 'nombre' => (string)($row['permiso_nombre'] ?? '')],
            'enabled' => $enabled,
            'estado' => $enabled ? 'HABILITADA' : 'RESTRINGIDA',
            'motivo' => (string)$row['motivo'],
            'actor' => [
                'id_superadmin' => $row['actor_id'] === null ? null : (int)$row['actor_id'],
                'email' => $row['actor_email'] ?? null,
            ],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function assertCompanyExists(PDO $pdo, int $idEmpresa): void
    {
        if ($idEmpresa <= 0) {
            throw new \RuntimeException('EMPRESA_NO_ENCONTRADA');
        }
        $st = $pdo->prepare('SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :empresa LIMIT 1');
        $st->execute([':empresa' => $idEmpresa]);
        if (!$st->fetchColumn()) {
            throw new \RuntimeException('EMPRESA_NO_ENCONTRADA');
        }
    }

    /** @return array{modulos:list<int>,permisos:list<int>,capacidades:list<string>} */
    private function commercialResources(PDO $pdo, int $idEmpresa, int $idPlan): array
    {
        $empty = ['modulos' => [], 'permisos' => [], 'capacidades' => []];
        if ($idPlan <= 0) {
            return $empty;
        }
        $complements = new CommercialComplementService();
        return $complements->hasStorage($pdo)
            ? $complements->activeResources($pdo, $idEmpresa, $idPlan)
            : $empty;
    }

    /** @return list<int> */
    private function compatibleComplementPermissions(PDO $pdo, int $idPlan): array
    {
        if ($idPlan <= 0 || !(new CommercialComplementService())->hasStorage($pdo)) {
            return [];
        }
        $st = $pdo->prepare('
            SELECT DISTINCT cp.id_permiso
            FROM admin.saas_complemento_permiso cp
            JOIN admin.saas_complemento c ON c.id_complemento = cp.id_complemento
            JOIN admin.saas_complemento_plan cplan
              ON cplan.id_complemento = c.id_complemento
             AND cplan.id_plan = :plan
            WHERE c.activo = true
              AND c.tipo_comercial = \'COMPLEMENTO\'
        ');
        $st->execute([':plan' => $idPlan]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<int> */
    private function commercialComplementPermissionIds(PDO $pdo): array
    {
        if (!(new CommercialComplementService())->hasStorage($pdo)) {
            return [];
        }
        $st = $pdo->query('
            SELECT DISTINCT cp.id_permiso
            FROM admin.saas_complemento_permiso cp
            JOIN admin.saas_complemento c ON c.id_complemento = cp.id_complemento
            WHERE c.activo = true
              AND c.tipo_comercial = \'COMPLEMENTO\'
        ');
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array{grupo_ui:bool,grupo_ui_nombre:bool,orden_ui:bool,visible_ui:bool} */
    private function permissionEditorialColumns(PDO $pdo): array
    {
        try {
            $st = $pdo->prepare('
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = \'pos_saas\'
                  AND table_name = \'permiso\'
                  AND column_name IN (\'grupo_ui\', \'grupo_ui_nombre\', \'orden_ui\', \'visible_ui\')
            ');
            $st->execute();
            $columns = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        } catch (\Throwable) {
            $columns = [];
        }

        return [
            'grupo_ui' => isset($columns['grupo_ui']),
            'grupo_ui_nombre' => isset($columns['grupo_ui_nombre']),
            'orden_ui' => isset($columns['orden_ui']),
            'visible_ui' => isset($columns['visible_ui']),
        ];
    }

    /** @param array{grupo_ui:bool,grupo_ui_nombre:bool,orden_ui:bool,visible_ui:bool} $columns @return list<string> */
    private function permissionEditorialSelectExpressions(array $columns): array
    {
        return [
            $columns['grupo_ui']
                ? "COALESCE(NULLIF(p.grupo_ui, ''), 'OTROS') AS grupo_ui"
                : "'OTROS' AS grupo_ui",
            $columns['grupo_ui_nombre']
                ? "COALESCE(NULLIF(p.grupo_ui_nombre, ''), 'Otros permisos') AS grupo_ui_nombre"
                : "'Otros permisos' AS grupo_ui_nombre",
            $columns['orden_ui'] ? 'COALESCE(p.orden_ui, 1000) AS orden_ui' : '1000 AS orden_ui',
            $columns['visible_ui'] ? 'COALESCE(p.visible_ui, true) AS visible_ui' : 'true AS visible_ui',
        ];
    }

    /** @param list<array<string,mixed>> $items @return array<string,list<array<string,mixed>>> */
    private function groupByOrigin(array $items): array
    {
        $grouped = ['PLAN' => [], 'COMPLEMENTO' => [], 'EXCEPCION_INTERNA' => []];
        foreach ($items as $item) {
            $origin = (string)($item['origen'] ?? 'PLAN');
            $grouped[$origin] ??= [];
            $grouped[$origin][] = $item;
        }
        return $grouped;
    }

    /** @param list<int> $ids */
    private static function postgresBigIntArray(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }

    private function assertPlanExists(PDO $pdo, int $idPlan): void
    {
        $st = $pdo->prepare('SELECT 1 FROM admin.saas_plan WHERE id_plan = :plan LIMIT 1');
        $st->execute([':plan' => $idPlan]);
        if (!$st->fetchColumn()) {
            throw new \RuntimeException('PLAN_NO_ENCONTRADO');
        }
    }

    private function assertExceptionStorage(PDO $pdo): void
    {
        $st = $pdo->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
        $st->execute([':table_name' => 'admin.empresa_entitlement_excepcion']);
        if (!$this->toBool($st->fetchColumn())) {
            throw new \RuntimeException('ENTITLEMENT_EXCEPTION_SCHEMA_MISSING');
        }
    }

    private function assertAdminActorStorage(PDO $pdo): void
    {
        $st = $pdo->query("
            SELECT EXISTS (
                SELECT 1
                FROM pg_constraint c
                JOIN pg_attribute a
                  ON a.attrelid = c.conrelid
                 AND a.attnum = ANY(c.conkey)
                WHERE c.conrelid = 'admin.empresa_entitlement_excepcion'::regclass
                  AND c.confrelid = 'admin.superadmin_user'::regclass
                  AND c.contype = 'f'
                  AND a.attname = 'actor_id'
            )
        ");
        if (!$this->toBool($st->fetchColumn())) {
            throw new \RuntimeException('ENTITLEMENT_EXCEPTION_SCHEMA_MISSING');
        }
    }

    private function assertActiveModule(PDO $pdo, int $idModulo): void
    {
        $st = $pdo->prepare('SELECT 1 FROM pos_saas.modulo WHERE id_modulo = :id AND COALESCE(estado, 1) = 1');
        $st->execute([':id' => $idModulo]);
        if (!$st->fetchColumn()) {
            throw new \InvalidArgumentException('EXCEPCION_MODULO_INVALIDO');
        }
    }

    private function assertPermissionExists(PDO $pdo, int $idPermiso): void
    {
        $st = $pdo->prepare('SELECT 1 FROM pos_saas.permiso WHERE id_permiso = :id');
        $st->execute([':id' => $idPermiso]);
        if (!$st->fetchColumn()) {
            throw new \InvalidArgumentException('EXCEPCION_PERMISO_INVALIDO');
        }
    }

    private function assertSaasCapabilityCeiling(PDO $pdo, int $idEmpresa, int $idModulo, int $idPermiso): void
    {
        $capabilities = new SaasCapabilityService();
        if ($idPermiso > 0) {
            $capabilities->assertPermissionCanBeEnabled($pdo, $idEmpresa, $idPermiso);
            return;
        }

        $capabilities->assertModuleCanBeEnabled($pdo, $idEmpresa, $idModulo);
        $gate = $pdo->prepare('SELECT id_permiso_gate FROM pos_saas.modulo WHERE id_modulo = :id');
        $gate->execute([':id' => $idModulo]);
        $idGate = (int)($gate->fetchColumn() ?: 0);
        if ($idGate > 0) {
            $capabilities->assertPermissionCanBeEnabled($pdo, $idEmpresa, $idGate);
        }
    }

    private function audit(PDO $pdo, int $actorId, string $actorEmail, string $action, int $idEmpresa, mixed $before, mixed $after): void
    {
        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (
                actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent
            ) VALUES (
                :actor, :email, :action, \'empresa\', :empresa, :before::jsonb, :after::jsonb, :ip, :ua
            )
        ');
        $st->execute([
            ':actor' => $actorId,
            ':email' => $actorEmail,
            ':action' => $action,
            ':empresa' => $idEmpresa,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function positiveId(mixed $value): int
    {
        return max(0, (int)$value);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        return $this->toBool($value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }
}

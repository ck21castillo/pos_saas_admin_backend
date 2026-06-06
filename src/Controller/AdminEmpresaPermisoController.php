<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminEmpresaPermisoController
{
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
        $st = $pdo->prepare("
          SELECT
            p.id_permiso,
            p.codigo,
            p.descripcion,
            COALESCE(ep.enabled, true) AS enabled
          FROM pos_saas.permiso p
          LEFT JOIN admin.empresa_permiso ep
            ON ep.id_empresa = :eid AND ep.id_permiso = p.id_permiso
          ORDER BY p.id_permiso ASC
        ");
        $st->execute([':eid' => $idEmpresa]);
        $items = $st->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(['ok' => true, 'id_empresa' => $idEmpresa, 'items' => $items]);
    }

    /** PUT /admin/empresas/{id}/permisos  body: { items: [{id_permiso, enabled}] } */
    public function save(int $idEmpresa, array $body): void
    {
        $actorId = (int)($_REQUEST['adminId'] ?? 0);
        $actorEmail = (string)($_REQUEST['adminEmail'] ?? '');

        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            Response::json(['error' => 'VALIDATION', 'message' => 'items debe ser un array'], 422);
        }

        // normaliza payload
        $norm = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $idPerm = (int)($it['id_permiso'] ?? 0);
            if ($idPerm <= 0) continue;

            $enabledRaw = $it['enabled'] ?? true;
            $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) $enabled = true;

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
            $ids = array_keys($norm);
            $in = implode(',', array_fill(0, count($ids), '?'));

            $validSt = $pdo->prepare("
              SELECT id_permiso
              FROM pos_saas.permiso
              WHERE id_permiso IN ($in)
            ");
            $validSt->execute($ids);
            $validIds = array_map('intval', $validSt->fetchAll(\PDO::FETCH_COLUMN));

            $validSet = array_flip($validIds);
            foreach ($ids as $pid) {
                if (!isset($validSet[$pid])) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "id_permiso inválido: $pid"], 422);
                }
            }

            // BEFORE snapshot
            $beforeSt = $pdo->prepare("
              SELECT id_permiso, enabled
              FROM admin.empresa_permiso
              WHERE id_empresa = ? AND id_permiso IN ($in)
              ORDER BY id_permiso
            ");
            $beforeSt->execute(array_merge([$idEmpresa], $ids));
            $before = $beforeSt->fetchAll(\PDO::FETCH_ASSOC);

            // UPSERT
            $up = $pdo->prepare("
              INSERT INTO admin.empresa_permiso (id_empresa, id_permiso, enabled)
              VALUES (:eid, :pid, :en)
              ON CONFLICT (id_empresa, id_permiso)
              DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
            ");

            foreach ($norm as $idPerm => $enabled) {
                $up->execute([
                    ':eid' => $idEmpresa,
                    ':pid' => $idPerm,
                    ':en'  => $enabled ? 1 : 0,
                ]);
            }

            // AFTER snapshot
            $afterSt = $pdo->prepare("
              SELECT id_permiso, enabled
              FROM admin.empresa_permiso
              WHERE id_empresa = ? AND id_permiso IN ($in)
              ORDER BY id_permiso
            ");
            $afterSt->execute(array_merge([$idEmpresa], $ids));
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

            $pdo->commit();
            Response::json(['ok' => true, 'id_empresa' => $idEmpresa, 'saved' => count($norm)]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $payload = ['error' => 'SERVER_ERROR'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }
}

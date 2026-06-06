<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminEmpresaModuloController
{
    /** GET /admin/empresas/{id}/modulos */
    public function list(int $idEmpresa): void
    {
        $pdo = Database::getConnection();

        // valida empresa
        $chk = $pdo->prepare("SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :id");
        $chk->execute([':id' => $idEmpresa]);
        if (!$chk->fetchColumn()) {
            Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
        }

        // catálogo + enabled por empresa (default true)
        $st = $pdo->prepare("
          SELECT
            m.id_modulo,
            m.nombre,
            m.ruta,
            m.icono,
            m.orden,
            m.parent_id,
            m.estado,
            m.id_permiso_gate,
            COALESCE(em.enabled, true) AS enabled
          FROM pos_saas.modulo m
          LEFT JOIN admin.empresa_modulo em
            ON em.id_empresa = :eid AND em.id_modulo = m.id_modulo
          ORDER BY m.orden ASC, m.id_modulo ASC
        ");
        $st->execute([':eid' => $idEmpresa]);
        $items = $st->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(['ok' => true, 'id_empresa' => $idEmpresa, 'items' => $items]);
    }

    /** PUT /admin/empresas/{id}/modulos  body: { items: [{id_modulo, enabled}] } */
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
            $idMod = (int)($it['id_modulo'] ?? 0);
            if ($idMod <= 0) continue;

            $enabledRaw = $it['enabled'] ?? true;
            $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) $enabled = true;

            $norm[$idMod] = (bool)$enabled;
        }

        if (!$norm) {
            Response::json(['error' => 'VALIDATION', 'message' => 'items vacío o inválido'], 422);
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            // valida empresa
            $chk = $pdo->prepare("SELECT id_empresa, nombre FROM pos_saas.empresa WHERE id_empresa = :id");
            $chk->execute([':id' => $idEmpresa]);
            $empresa = $chk->fetch(\PDO::FETCH_ASSOC);
            if (!$empresa) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
            }

            // valida que los modulos existan
            $ids = array_keys($norm);
            $in = implode(',', array_fill(0, count($ids), '?'));

            $validSt = $pdo->prepare("
              SELECT id_modulo
              FROM pos_saas.modulo
              WHERE id_modulo IN ($in)
            ");
            $validSt->execute($ids);
            $validIds = array_map('intval', $validSt->fetchAll(\PDO::FETCH_COLUMN));

            $validSet = array_flip($validIds);
            foreach ($ids as $mid) {
                if (!isset($validSet[$mid])) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "id_modulo inválido: $mid"], 422);
                }
            }

            // BEFORE snapshot
            $beforeSt = $pdo->prepare("
              SELECT id_modulo, enabled
              FROM admin.empresa_modulo
              WHERE id_empresa = ? AND id_modulo IN ($in)
              ORDER BY id_modulo
            ");
            $beforeSt->execute(array_merge([$idEmpresa], $ids));
            $before = $beforeSt->fetchAll(\PDO::FETCH_ASSOC);

            // UPSERT
            $up = $pdo->prepare("
              INSERT INTO admin.empresa_modulo (id_empresa, id_modulo, enabled)
              VALUES (:eid, :mid, :en)
              ON CONFLICT (id_empresa, id_modulo)
              DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
            ");

            // Sincroniza permiso gate del modulo con el estado del modulo.
            // Esto mantiene coherente POS sidebar + UI de RBAC (listPermissions).
            $permGateSt = $pdo->prepare("
              SELECT id_permiso_gate
              FROM pos_saas.modulo
              WHERE id_modulo = :mid
              LIMIT 1
            ");
            $upPerm = $pdo->prepare("
              INSERT INTO admin.empresa_permiso (id_empresa, id_permiso, enabled)
              VALUES (:eid, :pid, :en)
              ON CONFLICT (id_empresa, id_permiso)
              DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
            ");

            foreach ($norm as $idMod => $enabled) {
                $up->execute([
                    ':eid' => $idEmpresa,
                    ':mid' => $idMod,
                    ':en'  => $enabled ? 1 : 0,
                ]);

                $permGateSt->execute([':mid' => $idMod]);
                $idPermGate = (int)($permGateSt->fetchColumn() ?: 0);
                if ($idPermGate > 0) {
                    $upPerm->execute([
                        ':eid' => $idEmpresa,
                        ':pid' => $idPermGate,
                        ':en'  => $enabled ? 1 : 0,
                    ]);
                }
            }

            // AFTER snapshot
            $afterSt = $pdo->prepare("
              SELECT id_modulo, enabled
              FROM admin.empresa_modulo
              WHERE id_empresa = ? AND id_modulo IN ($in)
              ORDER BY id_modulo
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
                (:aid, :aem, 'EMPRESA_MODULOS_SAVE', 'empresa', :tid, :before::jsonb, :after::jsonb, :ip, :ua)
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

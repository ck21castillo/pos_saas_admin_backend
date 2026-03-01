<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminEmpresaController
{
    /** GET /admin/empresas?q=&limit=&offset= */
    public function list(): void
    {
        // ✅ Ya está protegido en public/index.php con requireAdmin()
        $pdo = Database::getConnection();

        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        // ⚠️ AJUSTA si tu tabla no es pos_saas.empresa
        $sql = "
          SELECT id_empresa, nombre, estado, created_at, updated_at
          FROM pos_saas.empresa
          WHERE (:q = '' OR nombre ILIKE '%' || :q || '%')
          ORDER BY id_empresa DESC
          LIMIT :limit OFFSET :offset
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':q', $q);
        $st->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $st2 = $pdo->prepare("
          SELECT COUNT(*)
          FROM pos_saas.empresa
          WHERE (:q = '' OR nombre ILIKE '%' || :q || '%')
        ");
        $st2->execute([':q' => $q]);
        $total = (int)$st2->fetchColumn();

        Response::json([
            'ok' => true,
            'total' => $total,
            'items' => $rows,
            'limit' => $limit,
            'offset' => $offset,
            'q' => $q,
        ]);
    }

    /** PATCH /admin/empresas/{id}/estado  body: { "estado": 0|1 } */
    public function setEstado(int $idEmpresa, array $body): void
    {
        // ✅ Ya está protegido en public/index.php con requireAdmin()
        // Actor viene inyectado por requireAdmin() del index.php
        $actorId = (int)($_REQUEST['adminId'] ?? 0);
        $actorEmail = (string)($_REQUEST['adminEmail'] ?? '');

        $estado = (int)($body['estado'] ?? -1);
        if (!in_array($estado, [0, 1], true)) {
            Response::json(['error' => 'VALIDATION', 'message' => 'estado debe ser 0 o 1'], 422);
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            // BEFORE (para auditoría)
            $beforeSt = $pdo->prepare("
              SELECT id_empresa, nombre, estado
              FROM pos_saas.empresa
              WHERE id_empresa = :id
            ");
            $beforeSt->execute([':id' => $idEmpresa]);
            $before = $beforeSt->fetch(\PDO::FETCH_ASSOC);

            if (!$before) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND'], 404);
            }

            $upd = $pdo->prepare("
              UPDATE pos_saas.empresa
              SET estado = :estado, updated_at = now()
              WHERE id_empresa = :id
            ");
            $upd->execute([':estado' => $estado, ':id' => $idEmpresa]);

            // AFTER
            $afterSt = $pdo->prepare("
              SELECT id_empresa, nombre, estado
              FROM pos_saas.empresa
              WHERE id_empresa = :id
            ");
            $afterSt->execute([':id' => $idEmpresa]);
            $after = $afterSt->fetch(\PDO::FETCH_ASSOC);

            // AUDIT
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $audit = $pdo->prepare("
              INSERT INTO admin.audit_log (
                actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent
              )
              VALUES (
                :aid, :aem, 'EMPRESA_ESTADO_SET', 'empresa', :tid, :before::jsonb, :after::jsonb, :ip, :ua
              )
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
            Response::json(['ok' => true, 'item' => $after]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $payload = ['error' => 'SERVER_ERROR'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }
}

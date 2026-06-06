<?php

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminHelpController
{
    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function listTickets(): void
    {
        $estado = trim((string)($_GET['estado'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = [];
        $params = [];

        if ($estado !== '') {
            $where[] = 't.estado = :estado';
            $params[':estado'] = strtoupper($estado);
        }
        if ($idEmpresa > 0) {
            $where[] = 't.id_empresa = :empresa';
            $params[':empresa'] = $idEmpresa;
        }
        if ($q !== '') {
            $where[] = '(t.asunto ILIKE :q OR t.contacto_nombre ILIKE :q OR t.contacto_email ILIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $sql = '
            SELECT
                t.id_ticket, t.id_empresa, e.nombre AS empresa_nombre,
                t.id_usuario, t.contacto_nombre, t.contacto_email,
                t.asunto, t.estado, t.prioridad, t.origen,
                t.created_at, t.updated_at, t.closed_at
            FROM admin.help_ticket t
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = t.id_empresa
        ';

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset';

        $pdo = Database::getConnection();
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        Response::json([
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function showTicket(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'INVALID_ID'], 400);
        }

        $pdo = Database::getConnection();

        $t = $pdo->prepare('
            SELECT
                t.id_ticket, t.id_empresa, e.nombre AS empresa_nombre,
                t.id_usuario, t.contacto_nombre, t.contacto_email,
                t.asunto, t.estado, t.prioridad, t.origen,
                t.created_at, t.updated_at, t.closed_at
            FROM admin.help_ticket t
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = t.id_empresa
            WHERE t.id_ticket = :id
            LIMIT 1
        ');
        $t->execute([':id' => $id]);
        $ticket = $t->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        $m = $pdo->prepare('
            SELECT id_message, actor_tipo, actor_id, mensaje, created_at
            FROM admin.help_ticket_message
            WHERE id_ticket = :id
            ORDER BY created_at ASC, id_message ASC
        ');
        $m->execute([':id' => $id]);

        Response::json([
            'ticket' => $ticket,
            'messages' => $m->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    public function reply(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $b = $this->jsonBody();
        $mensaje = trim((string)($b['mensaje'] ?? ''));
        $adminId = (int)($_REQUEST['adminId'] ?? 0);

        if ($id <= 0 || $mensaje === '') {
            Response::json(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('
                SELECT id_ticket
                FROM admin.help_ticket
                WHERE id_ticket = :id
                LIMIT 1
                FOR UPDATE
            ');
            $lock->execute([':id' => $id]);
            if (!$lock->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND'], 404);
            }

            $ins = $pdo->prepare('
                INSERT INTO admin.help_ticket_message
                    (id_ticket, actor_tipo, actor_id, mensaje)
                VALUES
                    (:t, \'ADMIN\', :a, :m)
            ');
            $ins->execute([
                ':t' => $id,
                ':a' => $adminId > 0 ? $adminId : null,
                ':m' => $mensaje,
            ]);

            $upd = $pdo->prepare('
                UPDATE admin.help_ticket
                SET estado = \'RESPONDIDO\',
                    updated_at = now(),
                    closed_at = NULL,
                    closed_by = NULL
                WHERE id_ticket = :id
            ');
            $upd->execute([':id' => $id]);

            $pdo->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $payload = ['error' => 'HELP_REPLY_FAILED'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }

    public function updateEstado(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $b = $this->jsonBody();
        $estado = strtoupper(trim((string)($b['estado'] ?? '')));
        $adminId = (int)($_REQUEST['adminId'] ?? 0);

        if ($id <= 0 || !in_array($estado, ['ABIERTO', 'EN_PROCESO', 'RESPONDIDO', 'CERRADO'], true)) {
            Response::json(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $pdo = Database::getConnection();
        $upd = $pdo->prepare('
            UPDATE admin.help_ticket
            SET estado = :s,
                updated_at = now(),
                closed_at = CASE WHEN :s = \'CERRADO\' THEN now() ELSE NULL END,
                closed_by = CASE WHEN :s = \'CERRADO\' THEN :a::bigint ELSE NULL::bigint END
            WHERE id_ticket = :id
        ');
        $upd->execute([
            ':s' => $estado,
            ':a' => $adminId > 0 ? $adminId : null,
            ':id' => $id,
        ]);

        if ($upd->rowCount() === 0) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        Response::json(['ok' => true, 'estado' => $estado]);
    }
}

<?php

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminHelpController
{
    private const ESTADOS = ['ABIERTO', 'EN_PROCESO', 'RESPONDIDO', 'CERRADO'];
    private const PRIORIDADES = ['BAJA', 'NORMAL', 'ALTA', 'URGENTE'];

    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function listTickets(): void
    {
        $estado = strtoupper(trim((string)($_GET['estado'] ?? '')));
        $prioridad = strtoupper(trim((string)($_GET['prioridad'] ?? '')));
        $q = trim((string)($_GET['q'] ?? ''));
        $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
        $assignedToRaw = trim((string)($_GET['assigned_to'] ?? ''));
        $fechaDesde = $this->dateParam('fecha_desde');
        $fechaHasta = $this->dateParam('fecha_hasta');
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        if ($estado !== '' && !in_array($estado, self::ESTADOS, true)) {
            Response::json(['error' => 'ESTADO_INVALIDO'], 422);
        }
        if ($prioridad !== '' && !in_array($prioridad, self::PRIORIDADES, true)) {
            Response::json(['error' => 'PRIORIDAD_INVALIDA'], 422);
        }

        $pdo = Database::getConnection();
        $hasAssignment = $this->columnExists($pdo, 'admin', 'help_ticket', 'assigned_to');

        $where = [];
        $params = [];

        if ($estado !== '') {
            $where[] = 't.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($prioridad !== '') {
            $where[] = 't.prioridad = :prioridad';
            $params[':prioridad'] = $prioridad;
        }
        if ($idEmpresa > 0) {
            $where[] = 't.id_empresa = :empresa';
            $params[':empresa'] = $idEmpresa;
        }
        if ($fechaDesde !== null) {
            $where[] = 't.created_at >= CAST(:fecha_desde AS date)';
            $params[':fecha_desde'] = $fechaDesde;
        }
        if ($fechaHasta !== null) {
            $where[] = 't.created_at < (CAST(:fecha_hasta AS date) + interval \'1 day\')';
            $params[':fecha_hasta'] = $fechaHasta;
        }
        if ($hasAssignment && $assignedToRaw !== '') {
            if ($assignedToRaw === 'none') {
                $where[] = 't.assigned_to IS NULL';
            } elseif (ctype_digit($assignedToRaw) && (int)$assignedToRaw > 0) {
                $where[] = 't.assigned_to = :assigned_to';
                $params[':assigned_to'] = (int)$assignedToRaw;
            }
        }
        if ($q !== '') {
            $where[] = '(t.asunto ILIKE :q OR t.contacto_nombre ILIKE :q OR t.contacto_email ILIKE :q OR CAST(t.id_ticket AS text) = :q_exact)';
            $params[':q'] = '%' . $q . '%';
            $params[':q_exact'] = $q;
        }

        $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
        $assignmentSelect = $hasAssignment
            ? 't.assigned_to, su.email AS assigned_to_email,'
            : 'NULL::bigint AS assigned_to, NULL::text AS assigned_to_email,';
        $assignmentJoin = $hasAssignment
            ? 'LEFT JOIN admin.superadmin_user su ON su.id_superadmin = t.assigned_to'
            : '';

        $count = $pdo->prepare('SELECT COUNT(*) FROM admin.help_ticket t ' . $whereSql);
        foreach ($params as $k => $v) {
            $count->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int)$count->fetchColumn();

        $sql = '
            SELECT
                t.id_ticket, t.id_empresa, e.nombre AS empresa_nombre,
                t.id_usuario, t.contacto_nombre, t.contacto_email,
                t.asunto, t.estado, t.prioridad, t.origen,
                ' . $assignmentSelect . '
                t.created_at, t.updated_at, t.closed_at
            FROM admin.help_ticket t
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = t.id_empresa
            ' . $assignmentJoin . '
        ' . $whereSql . '
            ORDER BY t.created_at DESC, t.id_ticket DESC
            LIMIT :limit OFFSET :offset
        ';

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        Response::json([
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'assignment_enabled' => $hasAssignment,
        ]);
    }

    public function listAdmins(): void
    {
        $pdo = Database::getConnection();
        $st = $pdo->query('
            SELECT id_superadmin, email
            FROM admin.superadmin_user
            WHERE estado = 1
            ORDER BY email ASC
        ');

        Response::json([
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    public function showTicket(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'INVALID_ID'], 400);
        }

        $pdo = Database::getConnection();
        $hasAssignment = $this->columnExists($pdo, 'admin', 'help_ticket', 'assigned_to');
        $assignmentSelect = $hasAssignment
            ? 't.assigned_to, su.email AS assigned_to_email,'
            : 'NULL::bigint AS assigned_to, NULL::text AS assigned_to_email,';
        $assignmentJoin = $hasAssignment
            ? 'LEFT JOIN admin.superadmin_user su ON su.id_superadmin = t.assigned_to'
            : '';

        $t = $pdo->prepare('
            SELECT
                t.id_ticket, t.id_empresa, e.nombre AS empresa_nombre,
                t.id_usuario, t.contacto_nombre, t.contacto_email,
                t.asunto, t.estado, t.prioridad, t.origen,
                ' . $assignmentSelect . '
                t.created_at, t.updated_at, t.closed_at
            FROM admin.help_ticket t
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = t.id_empresa
            ' . $assignmentJoin . '
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
            'assignment_enabled' => $hasAssignment,
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

        if ($id <= 0 || !in_array($estado, self::ESTADOS, true)) {
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

    public function updatePrioridad(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $body = $this->jsonBody();
        $prioridad = strtoupper(trim((string)($body['prioridad'] ?? '')));

        if ($id <= 0 || !in_array($prioridad, self::PRIORIDADES, true)) {
            Response::json(['error' => 'INVALID_PAYLOAD'], 400);
        }

        $pdo = Database::getConnection();
        $upd = $pdo->prepare('
            UPDATE admin.help_ticket
            SET prioridad = :prioridad,
                updated_at = now()
            WHERE id_ticket = :id
        ');
        $upd->execute([
            ':prioridad' => $prioridad,
            ':id' => $id,
        ]);

        if ($upd->rowCount() === 0) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        Response::json(['ok' => true, 'prioridad' => $prioridad]);
    }

    public function updateAsignacion(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $body = $this->jsonBody();
        $assignedTo = $body['assigned_to'] ?? null;

        if ($id <= 0) {
            Response::json(['error' => 'INVALID_ID'], 400);
        }

        $pdo = Database::getConnection();
        if (!$this->columnExists($pdo, 'admin', 'help_ticket', 'assigned_to')) {
            Response::json(['error' => 'HELP_ASSIGNMENT_NOT_READY'], 409);
        }

        $assignedId = null;
        if ($assignedTo !== null && $assignedTo !== '' && $assignedTo !== 'none') {
            $assignedId = (int)$assignedTo;
            if ($assignedId <= 0) {
                Response::json(['error' => 'ASSIGNED_TO_INVALIDO'], 422);
            }

            $admin = $pdo->prepare('
                SELECT id_superadmin
                FROM admin.superadmin_user
                WHERE id_superadmin = :id
                  AND estado = 1
                LIMIT 1
            ');
            $admin->execute([':id' => $assignedId]);
            if (!$admin->fetchColumn()) {
                Response::json(['error' => 'ASSIGNED_TO_NOT_FOUND'], 404);
            }
        }

        $upd = $pdo->prepare('
            UPDATE admin.help_ticket
            SET assigned_to = :assigned_to,
                updated_at = now()
            WHERE id_ticket = :id
        ');
        $upd->execute([
            ':assigned_to' => $assignedId,
            ':id' => $id,
        ]);

        if ($upd->rowCount() === 0) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        Response::json(['ok' => true, 'assigned_to' => $assignedId]);
    }

    private function dateParam(string $key): ?string
    {
        $value = trim((string)($_GET[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            Response::json(['error' => strtoupper($key) . '_INVALIDA'], 422);
        }
        return $value;
    }

    private function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $st = $pdo->prepare('
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = :schema
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ');
        $st->execute([
            ':schema' => $schema,
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$st->fetchColumn();
    }
}

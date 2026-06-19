<?php

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\InvitationMailerService;

class AdminOnboardingController
{
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function makeToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /** GET /onboarding/requests?estado=PENDIENTE&limit=25&offset=0 */
    public function listRequests(): void
    {
        $estado = trim((string)($_GET['estado'] ?? 'PENDIENTE'));
        if ($estado === '') {
            $estado = 'PENDIENTE';
        }
        if (!in_array($estado, ['PENDIENTE', 'APROBADA', 'RECHAZADA'], true)) {
            Response::error('ESTADO_INVALIDO', 400);
        }

        $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $pdo = Database::getConnection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM admin.invitation_request WHERE estado = :s');
        $count->execute([':s' => $estado]);
        $total = (int)$count->fetchColumn();

        $q = $pdo->prepare('
            SELECT id_request, email, empresa_nombre, telefono, plan_solicitado, mensaje, estado, created_at, ip, user_agent, notas
            FROM admin.invitation_request
            WHERE estado = :s
            ORDER BY created_at DESC, id_request DESC
            LIMIT :limit OFFSET :offset
        ');
        $q->bindValue(':s', $estado);
        $q->bindValue(':limit', $limit, PDO::PARAM_INT);
        $q->bindValue(':offset', $offset, PDO::PARAM_INT);
        $q->execute();

        Response::json([
            'rows' => $q->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'estado' => $estado,
        ]);
    }

    /** PATCH /onboarding/requests/:id  body: { estado, notas } */
    public function updateRequest(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('INVALID_ID', 400);
        }

        $b = $this->jsonBody();
        $estado = trim((string)($b['estado'] ?? ''));
        $notas = trim((string)($b['notas'] ?? ''));

        if (!in_array($estado, ['PENDIENTE', 'APROBADA', 'RECHAZADA'], true)) {
            Response::error('ESTADO_INVALIDO', 400);
        }

        $adminId = (int)($_REQUEST['adminId'] ?? 0);

        $pdo = Database::getConnection();
        $u = $pdo->prepare('
            UPDATE admin.invitation_request
            SET estado = :s,
                notas = :n,
                resolved_at = CASE WHEN :s <> \'PENDIENTE\' THEN now() ELSE resolved_at END,
                resolved_by = CASE WHEN :s <> \'PENDIENTE\' THEN :a ELSE resolved_by END
            WHERE id_request = :id
        ');
        $u->execute([
            ':s' => $estado,
            ':n' => ($notas !== '' ? $notas : null),
            ':a' => $adminId ?: null,
            ':id' => $id,
        ]);

        Response::json(['ok' => true]);
    }

    /**
     * POST /onboarding/invitations
     * body: { email, days?: 7, email_template?: "cliente"|"meta" }
     * Devuelve el codigo solo una vez.
     */
    public function createInvitation(): void
    {
        $b = $this->jsonBody();
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $days = (int)($b['days'] ?? 7);
        if ($days <= 0) {
            $days = 7;
        }
        $template = InvitationMailerService::normalizeTemplate((string)($b['email_template'] ?? $b['template'] ?? 'cliente'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('EMAIL_INVALIDO', 400);
        }

        $token = $this->makeToken();
        $hash = hash('sha256', $token);
        $adminId = (int)($_REQUEST['adminId'] ?? 0);
        $pdo = Database::getConnection();

        $ins = $pdo->prepare('
            INSERT INTO admin.invitation (email, token_hash, expires_at, created_by, estado)
            VALUES (:e, :h, now() + (:d || \' days\')::interval, :a, 1)
            RETURNING id_invitation, expires_at
        ');
        $ins->execute([
            ':e' => $email,
            ':h' => $hash,
            ':d' => (string)$days,
            ':a' => $adminId ?: null,
        ]);

        $row = $ins->fetch(PDO::FETCH_ASSOC);
        $emailSent = false;
        $emailError = null;

        try {
            InvitationMailerService::sendInvitation(
                $email,
                $token,
                (string)$row['expires_at'],
                $days,
                $template
            );
            $emailSent = true;
        } catch (\Throwable $e) {
            $emailError = 'MAIL_SEND_FAILED';
            error_log('[AdminOnboardingController::createInvitation] mail failed: ' . $e->getMessage());
        }

        Response::json([
            'ok' => true,
            'id_invitation' => (int)$row['id_invitation'],
            'expires_at' => $row['expires_at'],
            'invite_code' => $token,
            'email' => $email,
            'email_template' => $template,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ], 201);
    }

    /** GET /onboarding/invitations?email=...&limit=25&offset=0 */
    public function listInvitations(): void
    {
        $email = strtolower(trim((string)($_GET['email'] ?? '')));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = '';
        $params = [];
        if ($email !== '') {
            $where = ' WHERE email = :e';
            $params[':e'] = $email;
        }

        $pdo = Database::getConnection();

        $count = $pdo->prepare('SELECT COUNT(*) FROM admin.invitation' . $where);
        foreach ($params as $k => $v) {
            $count->bindValue($k, $v);
        }
        $count->execute();
        $total = (int)$count->fetchColumn();

        $q = $pdo->prepare("
            SELECT id_invitation, email, created_at, expires_at, used_at, estado,
                   used_by_company_id, used_by_user_id
            FROM admin.invitation
            {$where}
            ORDER BY created_at DESC, id_invitation DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $q->bindValue($k, $v);
        }
        $q->bindValue(':limit', $limit, PDO::PARAM_INT);
        $q->bindValue(':offset', $offset, PDO::PARAM_INT);
        $q->execute();

        Response::json([
            'rows' => $q->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'email' => $email,
        ]);
    }
}
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
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $chars = [];

        for ($i = 0; $i < 6; $i++) {
            $chars[] = $letters[random_int(0, strlen($letters) - 1)];
        }
        for ($i = 0; $i < 4; $i++) {
            $chars[] = $digits[random_int(0, strlen($digits) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
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

    private function normalizePlanCode(string $value): string
    {
        $code = strtoupper(trim($value));
        $code = str_replace(['-', ' '], '_', $code);
        $code = (string)preg_replace('/_+/', '_', $code);

        $aliases = [
            'NO_ESTOY_SEGURO' => 'NO_SEGURO',
            'NOSEGURO' => 'NO_SEGURO',
            'UNSURE' => 'NO_SEGURO',
            'POS_ESENCIAL' => 'ESENCIAL',
            'ANUAL_POS_ESENCIAL' => 'ESENCIAL',
            'ANUAL_ESENCIAL' => 'ESENCIAL',
            'POS_PRO' => 'PRO',
            'ANUAL_POS_PRO' => 'PRO',
            'ANUAL_PRO' => 'PRO',
            'ANUAL_SOPORTE_ESENCIAL' => 'SOPORTE_ESENCIAL',
            'ANUAL_SOPORTE_PRO' => 'SOPORTE_PRO',
        ];

        return $aliases[$code] ?? $code;
    }

    private function isUnsurePlan(string $value): bool
    {
        $code = $this->normalizePlanCode($value);
        return $code === '' || $code === 'NO_SEGURO';
    }

    /** @return array<string,mixed>|null */
    private function findInvitationRequest(PDO $pdo, int $idRequest, string $email): ?array
    {
        $q = $pdo->prepare('
            SELECT id_request, email, plan_solicitado
            FROM admin.invitation_request
            WHERE id_request = :id
              AND lower(email) = lower(:email)
            LIMIT 1
        ');
        $q->execute([':id' => $idRequest, ':email' => $email]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findActivePlan(PDO $pdo, string $code): ?array
    {
        $q = $pdo->prepare('
            SELECT id_plan, codigo, nombre
            FROM admin.saas_plan
            WHERE codigo = :codigo
              AND activo = true
            LIMIT 1
        ');
        $q->execute([':codigo' => $code]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * POST /onboarding/invitations
     * body: { email, days?: 7, email_template?: "cliente"|"meta", id_request?: number, plan_solicitado?: string }
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
        $idRequest = (int)($b['id_request'] ?? $b['id_solicitud'] ?? 0);
        $selectedPlan = $this->normalizePlanCode((string)($b['plan_solicitado'] ?? $b['plan_codigo'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('EMAIL_INVALIDO', 400);
        }

        $token = $this->makeToken();
        $hash = hash('sha256', $token);
        $adminId = (int)($_REQUEST['adminId'] ?? 0);
        $pdo = Database::getConnection();

        $finalPlan = null;
        if ($idRequest > 0) {
            $request = $this->findInvitationRequest($pdo, $idRequest, $email);
            if (!$request) {
                Response::error('SOLICITUD_NO_ENCONTRADA', 404);
            }

            $currentPlan = $this->normalizePlanCode((string)($request['plan_solicitado'] ?? ''));
            $finalPlan = $this->isUnsurePlan($selectedPlan) ? $currentPlan : $selectedPlan;

            if ($this->isUnsurePlan($finalPlan)) {
                Response::error('PLAN_REQUERIDO', 400);
            }

            $plan = $this->findActivePlan($pdo, $finalPlan);
            if (!$plan) {
                Response::error('PLAN_INVALIDO', 400);
            }

            $finalPlan = (string)$plan['codigo'];
            if ($currentPlan !== $finalPlan) {
                $updPlan = $pdo->prepare('
                    UPDATE admin.invitation_request
                    SET plan_solicitado = :plan
                    WHERE id_request = :id
                ');
                $updPlan->execute([':plan' => $finalPlan, ':id' => $idRequest]);
            }
        } elseif (!$this->isUnsurePlan($selectedPlan)) {
            $plan = $this->findActivePlan($pdo, $selectedPlan);
            if (!$plan) {
                Response::error('PLAN_INVALIDO', 400);
            }
            $finalPlan = (string)$plan['codigo'];
        }

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
            'plan_solicitado' => $finalPlan,
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
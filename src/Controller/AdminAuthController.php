<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminLoginRateLimitService;
use PosAdmin\Service\AdminOtpService;
use PosAdmin\Service\CookieService;
use PosAdmin\Service\JwtService;

final class AdminAuthController
{
    private function cookieOpts(): array
    {
        return [
            'secure'   => (($_ENV['COOKIE_SECURE'] ?? '0') === '1'),
            'samesite' => ($_ENV['COOKIE_SAMESITE'] ?? 'Lax'),
            'domain'   => ($_ENV['COOKIE_DOMAIN'] ?? ''),
            'path'     => '/',
        ];
    }

    private function cookieName(): string
    {
        return $_ENV['COOKIE_NAME'] ?? 'admin_access';
    }

    private function otpCookieName(): string
    {
        return $_ENV['ADMIN_OTP_COOKIE_NAME'] ?? 'admin_otp';
    }

    private function jwtSecret(): string
    {
        $s = (string)($_ENV['JWT_SECRET'] ?? '');
        if ($s === '') {
            throw new \RuntimeException('JWT_SECRET missing in .env');
        }
        return $s;
    }

    private function otpEnabled(): bool
    {
        return filter_var($_ENV['ADMIN_OTP_ENABLE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public function login(array $body): void
    {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $pass  = (string)($body['password'] ?? '');

        if ($email === '' || $pass === '') {
            Response::json(['error' => 'VALIDATION', 'message' => 'email y password son requeridos'], 422);
        }

        $pdo = Database::getConnection();
        $ip = $this->clientIp();
        $retryAfter = AdminLoginRateLimitService::retryAfterIfBlocked($pdo, $email, $ip);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            Response::json([
                'error' => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429);
        }

        $st = $pdo->prepare('
            SELECT id_superadmin, email, password_hash, estado
            FROM admin.superadmin_user
            WHERE lower(email) = lower(:email)
            LIMIT 1
        ');
        $st->execute([':email' => $email]);
        $row = $st->fetch();

        if (!$row || (int)$row['estado'] !== 1) {
            AdminLoginRateLimitService::recordFailure($pdo, $email, $ip);
            Response::json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        if (!password_verify($pass, (string)$row['password_hash'])) {
            AdminLoginRateLimitService::recordFailure($pdo, $email, $ip);
            Response::json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        AdminLoginRateLimitService::clear($pdo, $email, $ip);

        if ($this->otpEnabled()) {
            try {
                [$code, $intent, $ttl] = AdminOtpService::createLoginOtp(
                    (int)$row['id_superadmin'],
                    (string)$row['email']
                );
                AdminOtpService::sendLoginOtp((string)$row['email'], $code, $ttl);
                CookieService::setHttpOnly($this->otpCookieName(), $intent, $ttl, $this->cookieOpts());
            } catch (\Throwable $e) {
                error_log('[AdminAuthController::login] OTP failed: ' . $e->getMessage());
                Response::json(['error' => 'OTP_SEND_FAILED'], 500);
            }

            Response::json([
                'ok' => true,
                'otp_required' => true,
                'message' => 'OTP_REQUIRED',
                'email' => (string)$row['email'],
                'ttl' => AdminOtpService::ttl(),
            ]);
        }

        $this->issueSession($row);
    }

    public function otpVerify(array $body): void
    {
        if (!$this->otpEnabled()) {
            Response::json(['error' => 'OTP_DISABLED'], 400);
        }

        $code = preg_replace('/\D+/', '', (string)($body['code'] ?? ''));
        if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {
            Response::json(['error' => 'OTP_INVALID'], 422);
        }

        $intent = (string)($_COOKIE[$this->otpCookieName()] ?? '');
        if ($intent === '') {
            Response::json(['error' => 'OTP_INTENT_NOT_FOUND'], 401);
        }

        try {
            $admin = AdminOtpService::verifyLoginOtp($intent, $code);
        } catch (\Throwable $e) {
            Response::json(['error' => $this->otpErrorCode($e)], $this->otpErrorStatus($e));
        }

        CookieService::clear($this->otpCookieName(), $this->cookieOpts());
        $this->issueSession($admin);
    }

    public function otpResend(): void
    {
        if (!$this->otpEnabled()) {
            Response::json(['error' => 'OTP_DISABLED'], 400);
        }

        $intent = (string)($_COOKIE[$this->otpCookieName()] ?? '');
        if ($intent === '') {
            Response::json(['error' => 'OTP_INTENT_NOT_FOUND'], 401);
        }

        try {
            $otp = AdminOtpService::resendLoginOtp($intent);
            AdminOtpService::sendLoginOtp((string)$otp['email'], (string)$otp['code'], (int)$otp['ttl']);
            CookieService::setHttpOnly($this->otpCookieName(), (string)$otp['intent'], (int)$otp['ttl'], $this->cookieOpts());
        } catch (\Throwable $e) {
            Response::json(['error' => $this->otpErrorCode($e)], $this->otpErrorStatus($e));
        }

        Response::json([
            'ok' => true,
            'message' => 'OTP_RESENT',
            'ttl' => AdminOtpService::ttl(),
        ]);
    }

    public function me(): void
    {
        $cookie = $_COOKIE[$this->cookieName()] ?? '';
        if (!$cookie) {
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        try {
            $claims = JwtService::verify($cookie, $this->jwtSecret());
        } catch (\Throwable) {
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        if (($claims['typ'] ?? '') !== 'admin') {
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $sid = (int)($claims['sid'] ?? 0);
        if ($sid <= 0) {
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id_superadmin, email, estado FROM admin.superadmin_user WHERE id_superadmin = :id');
        $st->execute([':id' => $sid]);
        $row = $st->fetch();

        if (!$row || (int)$row['estado'] !== 1) {
            CookieService::clear($this->cookieName(), $this->cookieOpts());
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        Response::json([
            'ok' => true,
            'admin' => [
                'id' => (int)$row['id_superadmin'],
                'email' => (string)$row['email'],
            ],
        ]);
    }

    public function logout(): void
    {
        CookieService::clear($this->cookieName(), $this->cookieOpts());
        CookieService::clear($this->otpCookieName(), $this->cookieOpts());
        Response::json(['ok' => true, 'message' => 'LOGOUT_OK']);
    }

    private function clientIp(): string
    {
        $candidates = [];
        $trustProxy = filter_var($_ENV['ADMIN_TRUST_PROXY_HEADERS'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if ($trustProxy) {
            $candidates[] = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            $candidates[] = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
            $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwardedFor !== '') {
                $parts = explode(',', $forwardedFor);
                $candidates[] = trim((string)($parts[0] ?? ''));
            }
        }
        $candidates[] = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return 'unknown';
    }

    private function issueSession(array $admin): void
    {
        $id = (int)($admin['id_superadmin'] ?? 0);
        $email = (string)($admin['email'] ?? '');
        if ($id <= 0 || $email === '') {
            Response::json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $now = time();
        $ttl = (int)($_ENV['JWT_TTL_SECONDS'] ?? 900);
        $iss = (string)($_ENV['JWT_ISSUER'] ?? 'pos_saas_admin');

        $payload = [
            'typ' => 'admin',
            'iss' => $iss,
            'iat' => $now,
            'exp' => $now + $ttl,
            'sid' => $id,
            'sem' => $email,
        ];

        $token = JwtService::sign($payload, $this->jwtSecret());
        CookieService::setHttpOnly($this->cookieName(), $token, $ttl, $this->cookieOpts());

        Response::json([
            'ok' => true,
            'message' => 'LOGIN_OK',
            'admin' => [
                'id' => $id,
                'email' => $email,
            ],
        ]);
    }

    private function otpErrorCode(\Throwable $e): string
    {
        $code = $e->getMessage();
        return in_array($code, [
            'OTP_INVALID',
            'OTP_INTENT_NOT_FOUND',
            'OTP_ALREADY_USED',
            'OTP_EXPIRED',
            'OTP_TOO_MANY_ATTEMPTS',
            'OTP_RESEND_LIMIT',
            'ADMIN_DISABLED',
        ], true) ? $code : 'OTP_FAILED';
    }

    private function otpErrorStatus(\Throwable $e): int
    {
        return match ($this->otpErrorCode($e)) {
            'OTP_INVALID' => 422,
            'OTP_TOO_MANY_ATTEMPTS', 'OTP_RESEND_LIMIT' => 429,
            'OTP_INTENT_NOT_FOUND', 'OTP_ALREADY_USED', 'OTP_EXPIRED', 'ADMIN_DISABLED' => 401,
            default => 500,
        };
    }
}

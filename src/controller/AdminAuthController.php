<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
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

    private function jwtSecret(): string
    {
        $s = (string)($_ENV['JWT_SECRET'] ?? '');
        if ($s === '') {
            throw new \RuntimeException('JWT_SECRET missing in .env');
        }
        return $s;
    }

    public function login(array $body): void
    {
        $email = trim((string)($body['email'] ?? ''));
        $pass  = (string)($body['password'] ?? '');

        if ($email === '' || $pass === '') {
            Response::json(['error' => 'VALIDATION', 'message' => 'email y password son requeridos'], 422);
        }

        $pdo = Database::getConnection();
        $st = $pdo->prepare("
            SELECT id_superadmin, email, password_hash, estado
            FROM admin.superadmin_user
            WHERE email = :email
            LIMIT 1
        ");
        $st->execute([':email' => $email]);
        $row = $st->fetch();

        // no dar pistas
        if (!$row || (int)$row['estado'] !== 1) {
            Response::json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        if (!password_verify($pass, (string)$row['password_hash'])) {
            Response::json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        $now = time();
        $ttl = (int)($_ENV['JWT_TTL_SECONDS'] ?? 900);
        $iss = (string)($_ENV['JWT_ISSUER'] ?? 'pos_saas_admin');

        $payload = [
            'typ' => 'admin',
            'iss' => $iss,
            'iat' => $now,
            'exp' => $now + $ttl,
            'sid' => (int)$row['id_superadmin'],
            'sem' => (string)$row['email'],
        ];

        $token = JwtService::sign($payload, $this->jwtSecret());

        CookieService::setHttpOnly(
            $this->cookieName(),
            $token,
            $ttl,
            $this->cookieOpts()
        );

        Response::json([
            'ok' => true,
            'message' => 'LOGIN_OK',
            'admin' => [
                'id' => (int)$row['id_superadmin'],
                'email' => (string)$row['email'],
            ],
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

        // Validar que siga activo en BD
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT id_superadmin, email, estado FROM admin.superadmin_user WHERE id_superadmin = :id");
        $st->execute([':id' => $sid]);
        $row = $st->fetch();

        if (!$row || (int)$row['estado'] !== 1) {
            // borra cookie si está desactivado
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
        Response::json(['ok' => true, 'message' => 'LOGOUT_OK']);
    }
}

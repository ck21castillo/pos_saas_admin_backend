<?php
namespace PosAdmin\Middleware;

use PosAdmin\Core\Response;
use PosAdmin\Service\JwtService;
use PosAdmin\Service\CookieService;
use PosAdmin\Core\Database;

function requireAdmin(): array
{
    $cookieName = $_ENV['COOKIE_NAME'] ?? 'admin_access';
    $token = $_COOKIE[$cookieName] ?? '';

    if (!$token) {
        Response::json(['error' => 'UNAUTHENTICATED'], 401);
    }

    $secret = (string)($_ENV['JWT_SECRET'] ?? '');
    if ($secret === '') {
        Response::json(['error' => 'SERVER_MISCONFIG', 'message' => 'JWT_SECRET missing'], 500);
    }

    try {
        $claims = JwtService::verify($token, $secret);
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

    // check estado en BD
    $pdo = Database::getConnection();
    $st = $pdo->prepare("SELECT id_superadmin, email, estado FROM admin.superadmin_user WHERE id_superadmin = :id");
    $st->execute([':id' => $sid]);
    $row = $st->fetch();

    if (!$row || (int)$row['estado'] !== 1) {
        // limpiar cookie por seguridad
        CookieService::clear($cookieName, [
            'secure'   => (($_ENV['COOKIE_SECURE'] ?? '0') === '1'),
            'samesite' => ($_ENV['COOKIE_SAMESITE'] ?? 'Lax'),
            'domain'   => ($_ENV['COOKIE_DOMAIN'] ?? ''),
            'path'     => '/',
        ]);
        Response::json(['error' => 'UNAUTHENTICATED'], 401);
    }

    return [
        'id_superadmin' => (int)$row['id_superadmin'],
        'email' => (string)$row['email'],
        'claims' => $claims,
    ];
}

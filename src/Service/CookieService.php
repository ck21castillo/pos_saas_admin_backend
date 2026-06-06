<?php
namespace PosAdmin\Service;

final class CookieService
{
    public static function setHttpOnly(string $name, string $value, int $maxAgeSeconds, array $opts = []): void
    {
        $secure   = (bool)($opts['secure'] ?? false);
        $sameSite = (string)($opts['samesite'] ?? 'Lax');
        $domain   = $opts['domain'] ?? '';
        $path     = $opts['path'] ?? '/';

        setcookie($name, $value, [
            'expires'  => time() + $maxAgeSeconds,
            'path'     => $path,
            'domain'   => $domain ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    public static function clear(string $name, array $opts = []): void
    {
        $secure   = (bool)($opts['secure'] ?? false);
        $sameSite = (string)($opts['samesite'] ?? 'Lax');
        $domain   = $opts['domain'] ?? '';
        $path     = $opts['path'] ?? '/';

        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => $path,
            'domain'   => $domain ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
}

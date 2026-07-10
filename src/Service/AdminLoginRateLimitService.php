<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

final class AdminLoginRateLimitService
{
    public static function enabled(): bool
    {
        return filter_var($_ENV['ADMIN_LOGIN_RATE_LIMIT_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    public static function retryAfterIfBlocked(PDO $pdo, string $email, string $ip): int
    {
        if (!self::enabled()) {
            return 0;
        }

        self::cleanupExpired($pdo);
        $window = self::windowSeconds();
        $checks = [
            ['scope' => 'admin_login_email_ip', 'identity' => self::normalizeEmail($email) . '|' . $ip, 'limit' => self::emailIpMaxAttempts()],
            ['scope' => 'admin_login_ip', 'identity' => $ip, 'limit' => self::ipMaxAttempts()],
        ];

        $maxRetryAfter = 0;
        foreach ($checks as $check) {
            $row = self::bucket($pdo, $check['scope'], $check['identity']);
            if (!$row) {
                continue;
            }
            if ((int)$row['attempts'] < (int)$check['limit']) {
                continue;
            }

            $retryAfter = self::retryAfter($row['expires_at'] ?? null, $window);
            $maxRetryAfter = max($maxRetryAfter, $retryAfter);
        }

        return $maxRetryAfter;
    }

    public static function recordFailure(PDO $pdo, string $email, string $ip): void
    {
        if (!self::enabled()) {
            return;
        }

        $window = self::windowSeconds();
        self::hit($pdo, 'admin_login_email_ip', self::normalizeEmail($email) . '|' . $ip, $window);
        self::hit($pdo, 'admin_login_ip', $ip, $window);
    }

    public static function reauthRetryAfterIfBlocked(PDO $pdo, int $adminId, string $ip): int
    {
        if (!self::enabled()) {
            return 0;
        }

        self::cleanupExpired($pdo);
        $window = self::reauthWindowSeconds();
        $checks = [
            ['scope' => 'admin_reauth_admin_ip', 'identity' => $adminId . '|' . $ip, 'limit' => self::reauthAdminIpMaxAttempts()],
            ['scope' => 'admin_reauth_ip', 'identity' => $ip, 'limit' => self::reauthIpMaxAttempts()],
        ];

        $maxRetryAfter = 0;
        foreach ($checks as $check) {
            $row = self::bucket($pdo, $check['scope'], $check['identity']);
            if (!$row || (int)$row['attempts'] < (int)$check['limit']) {
                continue;
            }

            $retryAfter = self::retryAfter($row['expires_at'] ?? null, $window);
            $maxRetryAfter = max($maxRetryAfter, $retryAfter);
        }

        return $maxRetryAfter;
    }

    public static function recordReauthFailure(PDO $pdo, int $adminId, string $ip): void
    {
        if (!self::enabled()) {
            return;
        }

        $window = self::reauthWindowSeconds();
        self::hit($pdo, 'admin_reauth_admin_ip', $adminId . '|' . $ip, $window);
        self::hit($pdo, 'admin_reauth_ip', $ip, $window);
    }

    public static function clearReauth(PDO $pdo, int $adminId, string $ip): void
    {
        if (!self::enabled()) {
            return;
        }

        $st = $pdo->prepare('DELETE FROM admin.admin_auth_rate_limit_bucket WHERE key_hash IN (:a, :b)');
        $st->execute([
            ':a' => self::hashKey('admin_reauth_admin_ip', $adminId . '|' . $ip),
            ':b' => self::hashKey('admin_reauth_ip', $ip),
        ]);
    }

    public static function clear(PDO $pdo, string $email, string $ip): void
    {
        if (!self::enabled()) {
            return;
        }

        $st = $pdo->prepare('DELETE FROM admin.admin_auth_rate_limit_bucket WHERE key_hash IN (:a, :b)');
        $st->execute([
            ':a' => self::hashKey('admin_login_email_ip', self::normalizeEmail($email) . '|' . $ip),
            ':b' => self::hashKey('admin_login_ip', $ip),
        ]);
    }

    private static function hit(PDO $pdo, string $scope, string $identity, int $window): void
    {
        $hash = self::hashKey($scope, $identity);
        $st = $pdo->prepare("\n            INSERT INTO admin.admin_auth_rate_limit_bucket\n                (key_hash, scope, attempts, window_started_at, expires_at, last_hit_at, created_at, updated_at)\n            VALUES\n                (:hash, :scope, 1, now(), now() + (:window * interval '1 second'), now(), now(), now())\n            ON CONFLICT (key_hash) DO UPDATE SET\n                attempts = CASE\n                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN 1\n                    ELSE admin.admin_auth_rate_limit_bucket.attempts + 1\n                END,\n                window_started_at = CASE\n                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN now()\n                    ELSE admin.admin_auth_rate_limit_bucket.window_started_at\n                END,\n                expires_at = CASE\n                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN now() + (:window * interval '1 second')\n                    ELSE admin.admin_auth_rate_limit_bucket.expires_at\n                END,\n                last_hit_at = now(),\n                updated_at = now()\n        ");
        $st->execute([
            ':hash' => $hash,
            ':scope' => $scope,
            ':window' => $window,
        ]);
    }

    private static function bucket(PDO $pdo, string $scope, string $identity): ?array
    {
        $st = $pdo->prepare("\n            SELECT attempts, expires_at\n            FROM admin.admin_auth_rate_limit_bucket\n            WHERE key_hash = :hash\n              AND scope = :scope\n              AND expires_at > now()\n            LIMIT 1\n        ");
        $st->execute([
            ':hash' => self::hashKey($scope, $identity),
            ':scope' => $scope,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function cleanupExpired(PDO $pdo): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        $pdo->exec('DELETE FROM admin.admin_auth_rate_limit_bucket WHERE expires_at < now() - interval ' . "'1 day'");
    }

    private static function retryAfter(mixed $expiresAt, int $fallback): int
    {
        $expires = strtotime((string)$expiresAt);
        if ($expires === false) {
            return $fallback;
        }
        return max(1, $expires - time());
    }

    private static function hashKey(string $scope, string $identity): string
    {
        return hash('sha256', $scope . '|' . $identity);
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function windowSeconds(): int
    {
        return max(60, (int)($_ENV['ADMIN_LOGIN_RATE_LIMIT_WINDOW_SECONDS'] ?? 900));
    }

    private static function emailIpMaxAttempts(): int
    {
        return max(1, (int)($_ENV['ADMIN_LOGIN_RATE_LIMIT_EMAIL_IP_MAX'] ?? 8));
    }

    private static function ipMaxAttempts(): int
    {
        return max(1, (int)($_ENV['ADMIN_LOGIN_RATE_LIMIT_IP_MAX'] ?? 30));
    }

    private static function reauthWindowSeconds(): int
    {
        return max(60, (int)($_ENV['ADMIN_REAUTH_RATE_LIMIT_WINDOW_SECONDS'] ?? 900));
    }

    private static function reauthAdminIpMaxAttempts(): int
    {
        return max(1, (int)($_ENV['ADMIN_REAUTH_RATE_LIMIT_ADMIN_IP_MAX'] ?? 5));
    }

    private static function reauthIpMaxAttempts(): int
    {
        return max(1, (int)($_ENV['ADMIN_REAUTH_RATE_LIMIT_IP_MAX'] ?? 20));
    }
}

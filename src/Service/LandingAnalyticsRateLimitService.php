<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

final class LandingAnalyticsRateLimitService
{
    public static function enabled(): bool
    {
        return filter_var($_ENV['LANDING_ANALYTICS_RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }

    public static function retryAfterIfLimited(PDO $pdo, ?string $ip, string $visitorId): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $window = max(10, min(3600, (int)($_ENV['LANDING_ANALYTICS_RATE_LIMIT_WINDOW_SECONDS'] ?? 60)));
        $checks = [
            ['scope' => 'landing_analytics_visitor', 'identity' => $visitorId, 'max' => max(1, (int)($_ENV['LANDING_ANALYTICS_VISITOR_MAX'] ?? 6))],
        ];
        if ($ip !== null) {
            $checks[] = ['scope' => 'landing_analytics_ip', 'identity' => $ip, 'max' => max(1, (int)($_ENV['LANDING_ANALYTICS_IP_MAX'] ?? 30))];
        }

        $retryAfter = 0;
        foreach ($checks as $check) {
            $row = self::hit($pdo, $check['scope'], $check['identity'], $window);
            if ((int)$row['attempts'] > (int)$check['max']) {
                $until = strtotime((string)$row['expires_at']);
                $retryAfter = max($retryAfter, $until === false ? $window : max(1, $until - time()));
            }
        }

        return $retryAfter;
    }

    /** @return array{attempts:mixed,expires_at:mixed} */
    private static function hit(PDO $pdo, string $scope, string $identity, int $window): array
    {
        $statement = $pdo->prepare('
            INSERT INTO admin.admin_auth_rate_limit_bucket
                (key_hash, scope, attempts, window_started_at, expires_at, last_hit_at, created_at, updated_at)
            VALUES
                (:hash, :scope, 1, now(), now() + (:window * interval \'1 second\'), now(), now(), now())
            ON CONFLICT (key_hash) DO UPDATE SET
                attempts = CASE
                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN 1
                    ELSE admin.admin_auth_rate_limit_bucket.attempts + 1
                END,
                window_started_at = CASE
                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN now()
                    ELSE admin.admin_auth_rate_limit_bucket.window_started_at
                END,
                expires_at = CASE
                    WHEN admin.admin_auth_rate_limit_bucket.expires_at <= now() THEN now() + (:window * interval \'1 second\')
                    ELSE admin.admin_auth_rate_limit_bucket.expires_at
                END,
                last_hit_at = now(),
                updated_at = now()
            RETURNING attempts, expires_at
        ');
        $statement->execute([
            ':hash' => hash('sha256', $scope . '|' . $identity),
            ':scope' => $scope,
            ':window' => $window,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: ['attempts' => PHP_INT_MAX, 'expires_at' => null];
    }
}

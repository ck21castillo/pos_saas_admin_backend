<?php

declare(strict_types=1);

namespace PosAdmin\Core;

final class RequestSecurityPolicy
{
    /** @return list<string> */
    public static function allowedOrigins(): array
    {
        $allowed = [
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:5175',
            'https://bersanopos.com',
            'https://www.bersanopos.com',
        ];
        $extra = array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            explode(',', (string)($_ENV['CORS_ALLOWED_ORIGINS'] ?? ''))
        ), static fn(string $value): bool => $value !== '');

        return array_values(array_unique(array_merge($allowed, $extra)));
    }

    public static function isAllowedOrigin(string $origin): bool
    {
        return $origin !== '' && in_array($origin, self::allowedOrigins(), true);
    }

    public static function assertTrustedOriginForMutation(): void
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && !self::isAllowedOrigin($origin)) {
            Response::error('ORIGIN_NOT_ALLOWED', 403);
        }
    }

    public static function jsonBodyLimitBytes(): int
    {
        $configured = (int)($_ENV['ADMIN_JSON_MAX_BYTES'] ?? 1048576);
        return max(16384, min(5242880, $configured));
    }

    public static function isJsonRequest(): bool
    {
        return str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    }

    public static function declaredBodyExceeds(int $limit): bool
    {
        $declared = trim((string)($_SERVER['CONTENT_LENGTH'] ?? ''));
        return $declared !== '' && ctype_digit($declared) && (int)$declared > $limit;
    }
}

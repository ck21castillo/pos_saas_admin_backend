<?php

declare(strict_types=1);

namespace PosAdmin\Core;

final class ProductionSecurityConfig
{
    /** @param array<string, mixed>|null $environment */
    public static function validationError(?array $environment = null): ?string
    {
        $environment ??= array_merge($_SERVER, $_ENV);
        $appEnvironment = strtolower(trim((string)($environment['APP_ENV'] ?? '')));
        if (!in_array($appEnvironment, ['production', 'prod'], true)) {
            return null;
        }

        if ((string)($environment['APP_DEBUG'] ?? '0') === '1') {
            return 'APP_DEBUG_DISABLED_REQUIRED';
        }
        if ((string)($environment['COOKIE_SECURE'] ?? '0') !== '1') {
            return 'COOKIE_SECURE_REQUIRED';
        }
        if (!filter_var($environment['ADMIN_LOGIN_RATE_LIMIT_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
            return 'ADMIN_LOGIN_RATE_LIMIT_REQUIRED';
        }

        $secret = trim((string)($environment['JWT_SECRET'] ?? ''));
        if (strlen($secret) < 32 || str_contains(strtoupper($secret), 'CAMBIA_ESTO')) {
            return 'JWT_SECRET_SECURE_REQUIRED';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PosAdmin\Core\ProductionSecurityConfig;
use PosAdmin\Service\LandingAnalyticsInput;

function expectSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectSecurity(ProductionSecurityConfig::validationError([
    'APP_ENV' => 'production',
    'APP_DEBUG' => '0',
    'COOKIE_SECURE' => '1',
    'ADMIN_LOGIN_RATE_LIMIT_ENABLED' => 'true',
    'JWT_SECRET' => str_repeat('a', 32),
]) === null, 'La configuracion segura de produccion debe ser valida.');

expectSecurity(ProductionSecurityConfig::validationError([
    'APP_ENV' => 'production',
    'APP_DEBUG' => '1',
    'COOKIE_SECURE' => '1',
    'ADMIN_LOGIN_RATE_LIMIT_ENABLED' => 'true',
    'JWT_SECRET' => str_repeat('a', 32),
]) === 'APP_DEBUG_DISABLED_REQUIRED', 'Produccion no debe permitir debug.');

$input = LandingAnalyticsInput::normalize([
    'visitor_id' => 'visitor-12345678',
    'landing_path' => 'crear-negocio',
    'utm_source' => 'campana',
], 'navegador');
expectSecurity($input['landing_path'] === '/crear-negocio', 'Debe normalizar la ruta publica permitida.');
expectSecurity($input['utm']['utm_source'] === 'campana', 'Debe conservar UTM valido.');

try {
    LandingAnalyticsInput::normalize([
        'visitor_id' => str_repeat('x', 121),
        'landing_path' => '/',
    ]);
    throw new RuntimeException('Debe rechazar visitor_id excesivo.');
} catch (RuntimeException $e) {
    expectSecurity($e->getMessage() === 'VISITOR_ID_INVALIDO', 'Debe usar codigo seguro para visitor_id excesivo.');
}

try {
    LandingAnalyticsInput::normalize([
        'visitor_id' => 'visitor-12345678',
        'landing_path' => '/',
        'referrer' => str_repeat('x', 2049),
    ]);
    throw new RuntimeException('Debe rechazar referrer excesivo.');
} catch (RuntimeException $e) {
    expectSecurity($e->getMessage() === 'LANDING_ANALYTICS_FIELD_TOO_LONG', 'Debe limitar campos de analitica.');
}

echo "Security request policy: OK\n";

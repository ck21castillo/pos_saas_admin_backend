<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Core\Database;

function expectTenantCredentials(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectTenantCredentialsRequired(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        expectTenantCredentials($e->getMessage() === 'DB_TENANT_CREDENTIALS_REQUIRED', $message . ': ' . $e->getMessage());
        return;
    }

    throw new RuntimeException($message . ': se esperaba DB_TENANT_CREDENTIALS_REQUIRED');
}

$tenant = [
    'db_host' => '127.0.0.1',
    'db_port' => '5432',
    'db_user' => 'tenant_mapping_user',
];

expectTenantCredentialsRequired(
    static fn () => Database::tenantConnectionSettings('bersano_tenant_1', $tenant, [
        'APP_ENV' => 'production',
        'DB_USER' => 'control_user',
        'DB_PASSWORD' => 'control_password',
    ]),
    'PRODUCCION_SIN_CREDENCIALES_TENANT'
);

expectTenantCredentialsRequired(
    static fn () => Database::tenantConnectionSettings('bersano_tenant_1', $tenant, [
        'APP_ENV' => 'prod',
        'DB_TENANT_USER' => 'tenant_user',
        'DB_PASSWORD' => 'control_password',
    ]),
    'PRODUCCION_SIN_PASSWORD_TENANT'
);

$production = Database::tenantConnectionSettings('bersano_tenant_1', $tenant, [
    'APP_ENV' => 'production',
    'DB_TENANT_USER' => 'bersano_admin_ejecucion',
    'DB_TENANT_PASSWORD' => 'tenant_password',
    'DB_USER' => 'control_user',
    'DB_PASSWORD' => 'control_password',
]);
expectTenantCredentials($production['user'] === 'bersano_admin_ejecucion', 'PRODUCCION_NO_PRIORIZA_USUARIO_TENANT');
expectTenantCredentials($production['password'] === 'tenant_password', 'PRODUCCION_NO_PRIORIZA_PASSWORD_TENANT');
expectTenantCredentials(str_contains($production['dsn'], 'dbname=bersano_tenant_1'), 'DSN_TENANT_INVALIDO');

$local = Database::tenantConnectionSettings('bersano_tenant_1', $tenant, [
    'APP_ENV' => 'local',
    'DB_USER' => 'control_user',
    'DB_PASSWORD' => 'control_password',
]);
expectTenantCredentials($local['user'] === 'tenant_mapping_user', 'LOCAL_NO_CONSERVA_FALLBACK_DB_USER_TENANT');
expectTenantCredentials($local['password'] === 'control_password', 'LOCAL_NO_CONSERVA_FALLBACK_PASSWORD_CONTROL');

$localControlFallback = Database::tenantConnectionSettings('bersano_tenant_1', [], [
    'APP_ENV' => 'local',
    'DB_USER' => 'control_user',
    'DB_PASSWORD' => 'control_password',
]);
expectTenantCredentials($localControlFallback['user'] === 'control_user', 'LOCAL_NO_CONSERVA_FALLBACK_DB_USER_CONTROL');

expectTenantCredentialsRequired(
    static fn () => Database::tenantConnectionSettings('bersano_tenant_1', [], [
        'APP_ENV' => 'local',
    ]),
    'LOCAL_SIN_NINGUNA_CREDENCIAL'
);

echo "Credenciales tenant admin: OK\n";

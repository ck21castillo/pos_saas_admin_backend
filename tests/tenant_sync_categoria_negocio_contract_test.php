<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\AdminTenantSyncService;

function expectTenantCategorySync(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function companySyncPayload(AdminTenantSyncService $service, array $row, bool $includeCategory): array
{
    $method = new ReflectionMethod($service, 'companySyncPayload');
    $method->setAccessible(true);
    /** @var array<string, mixed> $payload */
    $payload = $method->invoke($service, $row, $includeCategory);
    return $payload;
}

function companyUpsertSql(AdminTenantSyncService $service, bool $includeCategory): string
{
    $method = new ReflectionMethod($service, 'companyUpsertSql');
    $method->setAccessible(true);
    return (string)$method->invoke($service, $includeCategory);
}

$company = [
    'id_empresa' => 17,
    'nombre' => 'Empresa categoria',
    'codigo' => 'EMPRESA_CATEGORIA',
    'created_at' => '2026-10-12 10:00:00+00',
    'updated_at' => '2026-10-12 10:00:00+00',
    'estado' => 1,
    'nit' => null,
    'direccion' => null,
    'telefono' => null,
    'logo_empresa' => null,
    'codigo_departamento' => null,
    'codigo_municipio' => null,
    'barrio' => null,
    'tipo_negocio' => 'FERRETERIA',
    'categoria_negocio' => 'FERRETERIA',
];

$service = new AdminTenantSyncService();
$payload = companySyncPayload($service, $company, true);
expectTenantCategorySync(
    ($payload[':tipo_negocio'] ?? null) === 'GENERAL',
    'El payload tenant debe enviar el perfil operativo canonico.'
);
expectTenantCategorySync(
    ($payload[':categoria_negocio'] ?? null) === 'FERRETERIA',
    'El payload tenant debe preservar la categoria comercial.'
);

$sqlWithCategory = companyUpsertSql($service, true);
expectTenantCategorySync(
    str_contains($sqlWithCategory, 'categoria_negocio')
        && str_contains($sqlWithCategory, ':categoria_negocio')
        && str_contains($sqlWithCategory, 'categoria_negocio = EXCLUDED.categoria_negocio'),
    'El UPSERT tenant debe insertar y actualizar categoria_negocio.'
);

$sqlWithoutCategory = companyUpsertSql($service, false);
expectTenantCategorySync(
    !str_contains($sqlWithoutCategory, 'categoria_negocio'),
    'El contrato anterior debe mantenerse mientras control no tenga la columna.'
);

$syncSource = file_get_contents(dirname(__DIR__) . '/src/Service/AdminTenantSyncService.php');
expectTenantCategorySync(
    is_string($syncSource) && str_contains($syncSource, 'TENANT_SCHEMA_CATEGORIA_NEGOCIO_REQUIRED'),
    'La ausencia de columna en tenant debe fallar con un error de esquema explicito.'
);

$businessConfigSource = file_get_contents(dirname(__DIR__) . '/src/Service/BusinessConfigService.php');
expectTenantCategorySync(
    is_string($businessConfigSource)
        && str_contains($businessConfigSource, "'categoria_negocio' => \$categoriaNegocio")
        && str_contains($businessConfigSource, "'categorias_disponibles' =>")
        && str_contains($businessConfigSource, "self::empresaColumnExists(\$pdo, 'categoria_negocio')"),
    'La configuracion administrativa debe exponer la categoria comercial y su catalogo cuando exista en control.'
);

echo "Tenant sync categoria negocio contract: OK\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\BusinessCategoryService;
use PosAdmin\Service\BusinessConfigService;

$expectedProfiles = [
    'GENERAL',
    'DROGUERIA',
    'TIENDA_MINIMARKET',
    'TECNOLOGIA_SERVICIO_TECNICO',
];
if (BusinessConfigService::validOperationalProfiles() !== $expectedProfiles) {
    throw new RuntimeException('El catalogo canonico de perfiles operativos no coincide.');
}
if (BusinessCategoryService::validOperationalProfiles() !== $expectedProfiles) {
    throw new RuntimeException('Categorias de negocio debe reutilizar perfiles operativos canonicos.');
}

$expectedSuggestionCapabilities = [
    'LOTES_VENCIMIENTOS',
    'PRODUCTOS_PESO',
    'PRODUCTOS_PRESENTACION',
    'SERIALES_EN_VENTA',
];
if (BusinessCategoryService::INITIAL_SUGGESTION_CAPABILITY_CODES !== $expectedSuggestionCapabilities) {
    throw new RuntimeException('El catalogo de sugerencias iniciales debe ser explicito y conservar seriales.');
}

$profileCatalog = BusinessCategoryService::operationalProfilesCatalog();
if (array_column($profileCatalog, 'codigo') !== $expectedProfiles) {
    throw new RuntimeException('El catalogo de presentacion debe derivar sus perfiles del contrato operativo.');
}
foreach ($profileCatalog as $profile) {
    if (!isset($profile['nombre'], $profile['descripcion'])
        || !is_string($profile['nombre'])
        || !is_string($profile['descripcion'])) {
        throw new RuntimeException('Cada perfil operativo debe incluir nombre y descripcion para el frontend.');
    }
}

$root = dirname(__DIR__);
$sql = (string)file_get_contents($root . '/database/manual/2026-10-12_catalogo_categorias_negocio.sql');
foreach ([
    'CREATE TABLE IF NOT EXISTS admin.saas_categoria_negocio',
    'CREATE TABLE IF NOT EXISTS admin.saas_categoria_negocio_capacidad',
    'ADD COLUMN IF NOT EXISTS categoria_negocio',
    "'TIENDA_TECNOLOGIA', 'Tienda de tecnología', NULL, 'GENERAL'",
    "'TECNOLOGIA_SERVICIO_TECNICO', 'Tecnología y servicio técnico', NULL, 'TECNOLOGIA_SERVICIO_TECNICO'",
    "'SERIALES_EN_VENTA'",
    "('TIENDA_TECNOLOGIA', 'SERIALES_EN_VENTA')",
    "('TECNOLOGIA_SERVICIO_TECNICO', 'SERIALES_EN_VENTA')",
    'WHERE empresa.categoria_negocio IS NULL',
] as $required) {
    if (!str_contains($sql, $required)) {
        throw new RuntimeException("Falta contrato de catalogo: {$required}");
    }
}

$operationalMigration = (string)file_get_contents($root . '/database/manual/2026-10-13_capacidades_operativas_producto.sql');
foreach ([
    "'DROGUERIA', 'LOTES_VENCIMIENTOS'",
    "'DROGUERIA', 'PRODUCTOS_PRESENTACION'",
    "'TIENDA_MINIMARKET', 'LOTES_VENCIMIENTOS'",
    "'TIENDA_MINIMARKET', 'PRODUCTOS_PESO'",
    "'TIENDA_TECNOLOGIA', 'SERIALES_EN_VENTA'",
    "'TECNOLOGIA_SERVICIO_TECNICO', 'SERIALES_EN_VENTA'",
    "WHERE codigo_capacidad = 'SOPORTE_TECNICO'",
    "DELETE FROM admin.saas_plan_capacidad",
    "WHERE codigo_capacidad = 'SERIALES_EN_VENTA'",
    'saas_empresa_capacidad_excepcion',
] as $required) {
    if (!str_contains($operationalMigration, $required)) {
        throw new RuntimeException("Migracion de capacidades operativas incompleta: {$required}");
    }
}

$controller = (string)file_get_contents($root . '/src/Controller/AdminCategoriaNegocioController.php');
foreach (['SAAS_CATEGORIA_NEGOCIO_CREATE', 'SAAS_CATEGORIA_NEGOCIO_UPDATE', 'admin.audit_log'] as $required) {
    if (!str_contains((string)file_get_contents($root . '/src/Service/BusinessCategoryService.php'), $required)) {
        throw new RuntimeException("Falta auditoria de categoria: {$required}");
    }
}
if (!str_contains($controller, 'BusinessCategoryService')) {
    throw new RuntimeException('El controlador debe delegar el catalogo al servicio.');
}
foreach (["'catalogos' =>", 'presentationCatalogs($pdo)'] as $required) {
    if (!str_contains($controller, $required)) {
        throw new RuntimeException("GET de categorias debe devolver catalogos: {$required}");
    }
}

$serviceSource = (string)file_get_contents($root . '/src/Service/BusinessCategoryService.php');
foreach ([
    'public static function operationalProfilesCatalog()',
    'public function presentationCatalogs(PDO $pdo): array',
    'FROM pos_saas.capacidad',
    'WHERE estado = 1',
    'codigo_capacidad = ANY(CAST(:codes AS text[]))',
    'INITIAL_SUGGESTION_CAPABILITY_CODES',
    "'perfiles_operativos' => self::operationalProfilesCatalog()",
    "'codigo_capacidad' => (string)\$row['codigo_capacidad']",
    "'activo' => self::toBool(\$row['estado'] ?? false)",
    "'habilitada_por_defecto' => self::toBool",
] as $required) {
    if (!str_contains($serviceSource, $required)) {
        throw new RuntimeException("Falta contrato de catalogos de presentacion: {$required}");
    }
}
foreach ([
    'SELECT codigo_capacidad FROM pos_saas.capacidad WHERE estado = 1',
    'CATEGORIA_NEGOCIO_CAPACIDAD_INVALIDA',
] as $required) {
    if (!str_contains($serviceSource, $required)) {
        throw new RuntimeException("La validacion de capacidades iniciales debe seguir usando el catalogo activo real: {$required}");
    }
}

$routes = (string)file_get_contents($root . '/public/index.php');
foreach ([
    "'/admin/categorias-negocio' && \$method === 'GET'",
    "'/admin/categorias-negocio' && \$method === 'POST'",
    '#^/admin/categorias-negocio/(\\d+)$#',
] as $required) {
    if (!str_contains($routes, $required)) {
        throw new RuntimeException("Falta ruta protegida de categorias: {$required}");
    }
}

echo "Contrato catalogo categorias de negocio: OK\n";

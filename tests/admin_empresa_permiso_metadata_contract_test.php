<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Controller\AdminEmpresaPermisoController;

$ref = new ReflectionClass(AdminEmpresaPermisoController::class);

$defaults = AdminEmpresaPermisoController::permissionEditorialDefaults();
$expectedDefaults = [
    'grupo_ui' => 'OTROS',
    'grupo_ui_nombre' => 'Otros permisos',
    'orden_ui' => 1000,
    'visible_ui' => true,
];

if ($defaults !== $expectedDefaults) {
    throw new RuntimeException('Los defaults editoriales de permisos no coinciden con el contrato.');
}

$normalizeColumns = $ref->getMethod('normalizePermissionEditorialColumns');
$normalizeColumns->setAccessible(true);
$selectExpressions = $ref->getMethod('permissionEditorialSelectExpressions');
$selectExpressions->setAccessible(true);
$orderBy = $ref->getMethod('permissionCatalogOrderBy');
$orderBy->setAccessible(true);
$normalizeItem = $ref->getMethod('normalizePermissionCatalogItem');
$normalizeItem->setAccessible(true);

$missingColumns = $normalizeColumns->invoke(null, []);
$fallbackSql = implode("\n", $selectExpressions->invoke(null, $missingColumns));

foreach ([
    "'OTROS' AS grupo_ui",
    "'Otros permisos' AS grupo_ui_nombre",
    '1000 AS orden_ui',
    'true AS visible_ui',
] as $needle) {
    if (!str_contains($fallbackSql, $needle)) {
        throw new RuntimeException("Falta fallback editorial en SQL: {$needle}");
    }
}

$presentColumns = $normalizeColumns->invoke(null, ['grupo_ui', 'grupo_ui_nombre', 'orden_ui', 'visible_ui']);
$presentSql = implode("\n", $selectExpressions->invoke(null, $presentColumns));

foreach ([
    "COALESCE(NULLIF(p.grupo_ui, ''), 'OTROS') AS grupo_ui",
    "COALESCE(NULLIF(p.grupo_ui_nombre, ''), 'Otros permisos') AS grupo_ui_nombre",
    'COALESCE(p.orden_ui, 1000) AS orden_ui',
    'COALESCE(p.visible_ui, true) AS visible_ui',
] as $needle) {
    if (!str_contains($presentSql, $needle)) {
        throw new RuntimeException("Falta columna editorial real en SQL: {$needle}");
    }
}

if ($orderBy->invoke(null) !== 'orden_ui ASC, p.codigo ASC') {
    throw new RuntimeException('El orden del catalogo de permisos debe ser orden_ui, codigo.');
}

$item = $normalizeItem->invoke(null, [
    'id_permiso' => 10,
    'codigo' => 'X__VER',
    'descripcion' => 'Demo',
    'visible_ui' => 't',
]);

foreach ($expectedDefaults as $key => $expected) {
    if (!array_key_exists($key, $item)) {
        throw new RuntimeException("Falta campo editorial {$key} en item normalizado.");
    }
}

if ($item['visible_ui'] !== true || $item['orden_ui'] !== 1000) {
    throw new RuntimeException('La normalizacion editorial no respeta tipos esperados.');
}

echo "Contrato metadata editorial de permisos: OK\n";

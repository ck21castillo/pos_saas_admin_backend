<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/src/Controller/AdminEmpresaConfiguracionController.php');

function expectOperationalCapabilityGuard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    'assertLotsCanBeDisabled',
    'BusinessConfigService::CAP_LOTES_VENCIMIENTOS',
    'array_key_exists($code, $capacidades) && !$capacidades[$code]',
    'tenantConnectionForCompany($idEmpresa)',
    'COALESCE(usa_lote, false) = true',
    'COALESCE(stock_lote, 0) <> 0',
    'CAPACIDAD_LOTES_EN_USO',
    'CAPACIDAD_LOTES_TENANT_NO_DISPONIBLE',
    'CAPACIDAD_LOTES_VALIDACION_TENANT_FALLIDA',
    'assertWeightCanBeDisabled',
    'CAP_PRODUCTOS_PESO',
    'UPPER(COALESCE(tipo_cantidad',
    "= \\'PESO\\'",
    'CAPACIDAD_PESO_EN_USO',
    'CAPACIDAD_PESO_TENANT_NO_DISPONIBLE',
    'CAPACIDAD_PESO_VALIDACION_TENANT_FALLIDA',
    'assertPresentationsCanBeDisabled',
    'CAP_PRODUCTOS_PRESENTACION',
    'FROM pos_saas.producto_presentacion',
    'COALESCE(estado, 1) = 1',
    'CAPACIDAD_PRESENTACIONES_EN_USO',
    'CAPACIDAD_PRESENTACIONES_TENANT_NO_DISPONIBLE',
    'CAPACIDAD_PRESENTACIONES_VALIDACION_TENANT_FALLIDA',
    'assertSerialsCanBeDisabled',
    'COALESCE(requiere_serial_imei, false) = true',
    'FROM pos_saas.venta_detalle_serial',
    'CAPACIDAD_SERIALES_EN_USO',
    'CAPACIDAD_SERIALES_TENANT_NO_DISPONIBLE',
    'CAPACIDAD_SERIALES_VALIDACION_TENANT_FALLIDA',
] as $required) {
    expectOperationalCapabilityGuard(
        str_contains($controller, $required),
        "Falta proteccion de capacidad operativa: {$required}"
    );
}

echo "Proteccion de desactivacion de capacidades operativas: OK\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\AdminSaasService;

$expected = [
    'TESORERIA__VER',
    'TESORERIA__CONFIGURAR',
    'TESORERIA__TRASLADAR',
    'TESORERIA__AJUSTAR',
    'COMPRAS__CORREGIR_CONFIRMADA',
];

if (AdminSaasService::coreBasePermissionCodes() !== $expected) {
    throw new RuntimeException('El catalogo base de Tesoreria no coincide con el contrato POS.');
}

foreach ($expected as $code) {
    if (!AdminSaasService::isCoreBasePermission($code)) {
        throw new RuntimeException("El permiso {$code} debe pertenecer a la base SaaS.");
    }
}

if (AdminSaasService::isCoreBasePermission('SOPORTE__VER')) {
    throw new RuntimeException('Una capacidad comercial no puede colarse en los permisos base.');
}

$serviceSource = file_get_contents(dirname(__DIR__) . '/src/Service/AdminSaasService.php') ?: '';
if (!str_contains($serviceSource, 'INSERT INTO admin.empresa_permiso')) {
    throw new RuntimeException('AdminSaasService debe aplicar permisos base por empresa.');
}

echo "Permisos base operativos en admin: OK\n";

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PosAdmin\Service\BusinessConfigService;

function expectBusinessTypeCatalog(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expected = [
    'GENERAL',
    'DROGUERIA',
    'TIENDA_MINIMARKET',
    'TECNOLOGIA_SERVICIO_TECNICO',
    'FERRETERIA',
    'MISCELANEA_PAPELERIA',
    'COLMENA',
    'ROPA_CALZADO',
    'COSMETICA_BELLEZA',
    'REPUESTOS_ACCESORIOS',
    'TIENDA_MASCOTAS',
];

expectBusinessTypeCatalog(
    BusinessConfigService::validBusinessTypes() === $expected,
    'El catalogo admin debe conservar paridad exacta con pos_saas.'
);
expectBusinessTypeCatalog(
    BusinessConfigService::normalizeBusinessType('tecnologia_servicio_tecnico') === 'TECNOLOGIA_SERVICIO_TECNICO',
    'El admin no debe degradar TECNOLOGIA_SERVICIO_TECNICO a GENERAL.'
);
expectBusinessTypeCatalog(
    BusinessConfigService::normalizeBusinessType('ferreteria') === 'FERRETERIA',
    'El admin no debe degradar FERRETERIA a GENERAL.'
);
expectBusinessTypeCatalog(
    BusinessConfigService::normalizeBusinessType('valor_desconocido') === 'GENERAL',
    'Los valores realmente desconocidos deben conservar fallback GENERAL.'
);

echo "Business type catalog parity: OK\n";

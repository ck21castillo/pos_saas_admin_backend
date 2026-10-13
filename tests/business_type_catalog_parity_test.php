<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PosAdmin\Service\BusinessConfigService;

function expectOperationalProfile(bool $condition, string $message): void
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
];

expectOperationalProfile(
    BusinessConfigService::validOperationalProfiles() === $expected,
    'El contrato operativo debe contener exclusivamente los cuatro perfiles canonicos.'
);
expectOperationalProfile(
    BusinessConfigService::validBusinessTypes() === $expected,
    'El alias de compatibilidad no debe volver a exponer categorias comerciales como tipo escribible.'
);
foreach ($expected as $profile) {
    expectOperationalProfile(
        BusinessConfigService::normalizeOperationalProfile(strtolower($profile)) === $profile,
        "El perfil operativo $profile debe conservarse al normalizar."
    );
}
expectOperationalProfile(
    BusinessConfigService::normalizeOperationalProfile('ferreteria') === 'GENERAL',
    'Una categoria comercial historica leida debe normalizarse conservadoramente a GENERAL.'
);
expectOperationalProfile(
    !in_array('FERRETERIA', BusinessConfigService::validOperationalProfiles(), true),
    'FERRETERIA debe rechazarse como un nuevo tipo_negocio.'
);
$controllerSource = file_get_contents(__DIR__ . '/../src/Controller/AdminEmpresaConfiguracionController.php');
expectOperationalProfile(
    is_string($controllerSource)
        && str_contains($controllerSource, "['categoria_negocio']")
        && str_contains($controllerSource, 'resolveActiveForCompany')
        && str_contains($controllerSource, 'categoria_negocio = :categoria, tipo_negocio = :tipo')
        && !str_contains($controllerSource, 'validBusinessTypes()'),
    'El guardado de configuracion debe resolver una categoria activa y derivar atomically el perfil operativo.'
);
expectOperationalProfile(
    BusinessConfigService::normalizeOperationalProfile('valor_desconocido') === 'GENERAL',
    'Los valores realmente desconocidos deben conservar fallback GENERAL.'
);

echo "Business operational profile contract: OK\n";

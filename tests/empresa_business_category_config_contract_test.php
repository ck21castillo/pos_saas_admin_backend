<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\SaasCapabilityService;

function expectCompanyCategoryConfig(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectCompanyCategoryConfig(
    !in_array('SERIALES_EN_VENTA', SaasCapabilityService::MANAGED_CODES, true),
    'SERIALES_EN_VENTA debe ser configurable por empresa, no por SaaS.'
);

$root = dirname(__DIR__);
$configService = (string)file_get_contents($root . '/src/Service/BusinessConfigService.php');
foreach (['categorias_disponibles', 'editable_por_empresa', "'gestion' =>"] as $required) {
    expectCompanyCategoryConfig(str_contains($configService, $required), "Falta metadata de configuracion: {$required}");
}

$controller = (string)file_get_contents($root . '/src/Controller/AdminEmpresaConfiguracionController.php');
foreach (['categoria_negocio', 'resolveActiveForCompany', 'SET categoria_negocio = :categoria, tipo_negocio = :tipo'] as $required) {
    expectCompanyCategoryConfig(str_contains($controller, $required), "La empresa no deriva perfil desde categoria: {$required}");
}
foreach (['assertSerialsCanBeDisabled', 'CAPACIDAD_SERIALES_EN_USO'] as $required) {
    expectCompanyCategoryConfig(str_contains($controller, $required), "Falta proteccion de seriales: {$required}");
}
expectCompanyCategoryConfig(
    !str_contains($controller, "\$rawTipo = strtoupper"),
    'No se debe aceptar tipo_negocio como decision directa de cliente.'
);

echo "Contrato configuracion empresa por categoria: OK\n";

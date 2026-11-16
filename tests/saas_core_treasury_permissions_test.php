<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\AdminSaasService;

$expected = [
    'TESORERIA__VER',
    'TESORERIA__CONFIGURAR',
    'TESORERIA__TRASLADAR',
    'TESORERIA__AJUSTAR',
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

if (AdminSaasService::isCoreBasePermission('COMPRAS__CORREGIR_CONFIRMADA')) {
    throw new RuntimeException('Compras debe depender exclusivamente de la matriz comercial del plan.');
}

$adminSaasSource = file_get_contents(dirname(__DIR__) . '/src/Service/AdminSaasService.php') ?: '';
$entitlementSource = file_get_contents(dirname(__DIR__) . '/src/Service/CompanyEntitlementService.php') ?: '';
if (
    !str_contains($entitlementSource, 'public function materializePlan')
    || !str_contains($entitlementSource, 'INSERT INTO admin.empresa_permiso')
    || !str_contains($entitlementSource, "'TESORERIA__VER'")
    || !str_contains($entitlementSource, "'TESORERIA__CONFIGURAR'")
    || !str_contains($entitlementSource, "'TESORERIA__TRASLADAR'")
    || !str_contains($entitlementSource, "'TESORERIA__AJUSTAR'")
) {
    throw new RuntimeException('CompanyEntitlementService debe materializar los permisos base operativos.');
}

if (
    !str_contains($adminSaasSource, '(new CompanyEntitlementService())->materializePlan($pdo, $idEmpresa, $idPlan)')
    || str_contains($adminSaasSource, 'ensureCompanyCoreBasePermissions')
) {
    throw new RuntimeException('La suscripcion debe usar la materializacion real y no un helper huérfano.');
}

echo "Permisos base operativos en admin: OK\n";

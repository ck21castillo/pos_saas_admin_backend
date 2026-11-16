<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\AdminSaasService;
use PosAdmin\Service\CompanyEntitlementService;

function expectEntitlement(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Service/CompanyEntitlementService.php');
$controller = (string)file_get_contents($root . '/src/Controller/AdminEmpresaEntitlementController.php');
$routes = (string)file_get_contents($root . '/public/index.php');
$saas = (string)file_get_contents($root . '/src/Service/AdminSaasService.php');
$migration = (string)file_get_contents($root . '/database/manual/2026-11-02_entitlement_excepcion_actor_superadmin.sql');
$legacyTreasuryRollout = (string)file_get_contents($root . '/database/manual/2026-10-08_tesoreria_permisos_base.sql');

expectEntitlement(
    AdminSaasService::coreBasePermissionCodes() === [
        'TESORERIA__VER',
        'TESORERIA__CONFIGURAR',
        'TESORERIA__TRASLADAR',
        'TESORERIA__AJUSTAR',
    ] && !AdminSaasService::isCoreBasePermission('COMPRAS__CORREGIR_CONFIRMADA'),
    'COMPRAS__CORREGIR_CONFIRMADA debe depender de la matriz del plan.'
);

expectEntitlement(
    substr_count($legacyTreasuryRollout, "'COMPRAS__CORREGIR_CONFIRMADA'") === 3,
    'El rollout historico puede conservar Compras en catalogo y roles, pero no concederlo a todos los planes o empresas.'
);

expectEntitlement(
    str_contains($service, 'INSERT INTO admin.empresa_modulo')
        && str_contains($service, 'INSERT INTO admin.empresa_permiso')
        && str_contains($service, 'COALESCE(spm.enabled, false)')
        && str_contains($service, 'COALESCE(spp.enabled, false)'),
    'Cambiar de plan debe rematerializar modulos y permisos, incluyendo los recursos retirados.'
);

expectEntitlement(
    str_contains($saas, 'CompanyEntitlementService())->materializePlan')
        && str_contains($saas, 'entitlements_materializados'),
    'La suscripcion debe materializar su matriz comercial dentro de la transaccion.'
);

expectEntitlement(
    str_contains($service, 'assertSaasCapabilityCeiling')
        && str_contains($service, 'assertModuleCanBeEnabled')
        && str_contains($service, 'assertPermissionCanBeEnabled'),
    'Una excepcion de acceso no puede exceder una capacidad SaaS apagada.'
);

[$materializeSource] = explode('public function materializeCurrent', $service, 2);
expectEntitlement(
    !str_contains($materializeSource, 'DELETE FROM admin.empresa_entitlement_excepcion'),
    'La materializacion del plan no puede borrar excepciones internas.'
);

expectEntitlement(
    CompanyEntitlementService::resolveEffective(false, false, true) === ['enabled' => true, 'origen' => 'EXCEPCION_INTERNA']
        && CompanyEntitlementService::resolveEffective(true, false, null) === ['enabled' => false, 'origen' => 'PLAN']
        && CompanyEntitlementService::resolveEffective(false, null, null) === ['enabled' => false, 'origen' => 'PLAN'],
    'La prioridad debe ser excepcion, materializacion y finalmente matriz del plan.'
);

expectEntitlement(
    CompanyEntitlementService::unavailablePermissionReason(true, false, false, false, false, false) === 'RESTRINGIDO_EXCEPCION'
        && CompanyEntitlementService::unavailablePermissionReason(true, false, false, true, true, null) === 'REQUIERE_COMPLEMENTO'
        && CompanyEntitlementService::unavailablePermissionReason(true, false, false, false, true, null) === 'NO_DISPONIBLE'
        && CompanyEntitlementService::unavailablePermissionReason(true, false, false, false, false, null) === 'NO_INCLUIDO_PLAN'
        && CompanyEntitlementService::unavailablePermissionReason(false, false, false, false, false, null) === 'NO_DISPONIBLE',
    'Los permisos no disponibles deben exponer un motivo comercial seguro y determinista.'
);

expectEntitlement(
    str_contains($service, "'permisos_no_disponibles' =>")
        && str_contains($service, 'compatibleComplementPermissions')
        && str_contains($service, 'COALESCE(p.visible_ui, true) AS visible_ui')
        && str_contains($service, 'id_modulo_asociado')
        && str_contains($service, 'ruta_modulo_asociado'),
    'El contrato debe separar permisos no disponibles y exponer metadatos editoriales sin reglas en frontend.'
);

expectEntitlement(
    str_contains($controller, 'mb_strlen($motivo) < 10') === false
        && str_contains($service, 'mb_strlen($motivo) < 10')
        && str_contains($routes, '/accesos-efectivos')
        && str_contains($routes, '/entitlement-excepciones')
        && str_contains($routes, 'requireAdmin();'),
    'Las excepciones deben tener motivo, rutas internas y proteccion administrativa.'
);

expectEntitlement(
    str_contains($migration, 'REFERENCES admin.superadmin_user(id_superadmin)')
        && str_contains($migration, 'FROM admin.superadmin_user u')
        && str_contains($service, 'assertAdminActorStorage'),
    'El actor de una excepcion debe ser el superadmin autenticado.'
);

echo "Entitlements comerciales por empresa: OK\n";

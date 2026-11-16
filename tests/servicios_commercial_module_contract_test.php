<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function expectServiciosCommercialModule(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$migration = (string)file_get_contents(
    $root . '/database/manual/2026-11-04_servicios_modulo_comercial_control.sql'
);
$entitlements = (string)file_get_contents($root . '/src/Service/CompanyEntitlementService.php');
$tenantSync = (string)file_get_contents($root . '/src/Service/AdminTenantSyncService.php');

expectServiciosCommercialModule(
    str_contains($migration, "'Servicios', '/servicios', 'sell', 0")
        && str_contains($migration, "UPDATE pos_saas.modulo m\nSET nombre = 'Servicios',\n    icono = 'sell'")
        && str_contains($migration, "m.ruta = '/servicios'")
        && str_contains($migration, 'IS DISTINCT FROM')
        && str_contains($migration, "codigo = 'PRODUCTOS__VER'")
        && str_contains($migration, 'SERVICIOS_REQUIERE_PRODUCTOS_VER'),
    'Servicios debe normalizar cualquier icono previo a sell y conservar PRODUCTOS__VER como gate.'
);

expectServiciosCommercialModule(
    str_contains($migration, 'SERVICIOS_ORDEN_REFERENCIA_FALTANTE')
        && str_contains($migration, 'SERVICIOS_ORDEN_REFERENCIA_INVALIDO')
        && str_contains($migration, 'servicios_orden <> deudores_orden + 1')
        && str_contains($migration, "AND ruta <> '/servicios'"),
    'Servicios debe ubicarse de forma idempotente debajo de Deudores y encima de Productos.'
);

expectServiciosCommercialModule(
    str_contains($migration, "'ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO'")
        && str_contains($migration, 'INSERT INTO admin.saas_plan_modulo')
        && str_contains($migration, 'ON CONFLICT (id_plan, id_modulo) DO UPDATE')
        && str_contains($migration, 'SERVICIOS_PLANES_INCOMPLETOS'),
    'Servicios debe estar habilitado de forma idempotente en los cuatro planes comerciales.'
);

expectServiciosCommercialModule(
    !str_contains($migration, 'INSERT INTO admin.empresa_modulo')
        && !str_contains($migration, 'INSERT INTO admin.empresa_permiso')
        && !str_contains($migration, 'PRECONTABILIDAD'),
    'La migracion no debe materializar empresas ni alterar Precontabilidad.'
);

expectServiciosCommercialModule(
    str_contains($entitlements, 'COALESCE(spm.enabled, false)')
        && str_contains($entitlements, "'origen' => 'PLAN'"),
    'La materializacion y la lectura efectiva existentes deben derivar Servicios desde la matriz del plan.'
);

expectServiciosCommercialModule(
    str_contains($tenantSync, '$this->syncCompanyRow($control, $tenant, $companyId);')
        && str_contains($tenantSync, '$this->syncCompanyCapabilities($control, $tenant, $companyId);')
        && !str_contains($tenantSync, 'syncCompanyModules')
        && !str_contains($tenantSync, 'syncCompanyPermissions'),
    'BUSINESS_CONFIG solo puede sincronizar empresa y capacidades; Servicios procede del catálogo central.'
);

echo "Modulo comercial Servicios: OK\n";

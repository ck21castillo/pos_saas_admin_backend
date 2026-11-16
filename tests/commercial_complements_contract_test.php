<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function expectCommercialComplement(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$catalog = (string)file_get_contents($root . '/src/Service/CommercialComplementService.php');
$entitlements = (string)file_get_contents($root . '/src/Service/CompanyEntitlementService.php');
$capabilities = (string)file_get_contents($root . '/src/Service/SaasCapabilityService.php');
$routes = (string)file_get_contents($root . '/public/index.php');
$modules = (string)file_get_contents($root . '/src/Controller/AdminEmpresaModuloController.php');
$permissions = (string)file_get_contents($root . '/src/Controller/AdminEmpresaPermisoController.php');
$saas = (string)file_get_contents($root . '/src/Controller/AdminSaasController.php');
$migration = (string)file_get_contents($root . '/database/manual/2026-11-02_complementos_comerciales.sql');

expectCommercialComplement(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS admin.saas_complemento')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS admin.empresa_saas_complemento')
        && str_contains($migration, "'COMPLEMENTO', 'SOLO_INTERNO'")
        && str_contains($migration, 'saas_complemento_dependencia'),
    'Debe existir un catalogo comercial con activaciones, compatibilidad y dependencias.'
);

expectCommercialComplement(
    str_contains($migration, "'WHATSAPP_POS'")
        && str_contains($migration, "'WHATSAPP_SOPORTE'")
        && str_contains($migration, "'PRECONTABILIDAD_INTERNA'")
        && str_contains($migration, "'WHATSAPP__POS_ENVIAR_COMPROBANTE'")
        && str_contains($migration, "'WHATSAPP__SOPORTE_ENVIAR_COMPROBANTE'"),
    'WhatsApp debe tener complementos independientes y Precontabilidad una entrada solo interna.'
);

expectCommercialComplement(
    str_contains($catalog, 'COMPLEMENTO_INCOMPATIBLE_CON_PLAN')
        && str_contains($catalog, 'assertDependencies')
        && str_contains($catalog, 'ON CONFLICT (id_empresa, id_complemento)')
        && str_contains($catalog, "c.tipo_comercial = 'COMPLEMENTO'"),
    'La activacion debe ser idempotente, compatible con el plan y validar dependencias.'
);

expectCommercialComplement(
    str_contains($catalog, 'eligibilityForComplement')
        && str_contains($catalog, 'INCLUIDO_EN_PLAN')
        && str_contains($catalog, 'BLOQUEADO_CAPACIDAD')
        && str_contains($catalog, 'COMPLEMENTO_YA_INCLUIDO_EN_PLAN')
        && str_contains($catalog, 'COMPLEMENTO_REQUIERE_CAPACIDAD')
        && str_contains($catalog, 'saas_plan_modulo')
        && str_contains($catalog, 'saas_plan_permiso')
        && str_contains($catalog, 'saas_plan_capacidad')
        && !str_contains($catalog, 'empresa_modulo'),
    'La elegibilidad debe comparar recursos canonicos del plan, bloquear capacidades faltantes y rechazar activaciones redundantes.'
);

expectCommercialComplement(
    str_contains($entitlements, 'commercialResources')
        && str_contains($entitlements, "'COMPLEMENTO'")
        && str_contains($entitlements, "'EXCEPCION_INTERNA'")
        && !str_contains($entitlements, 'materialized_enabled'),
    'El acceso efectivo no puede depender de ediciones directas de la materializacion tecnica.'
);

expectCommercialComplement(
    str_contains($capabilities, 'activeResources($pdo, $idEmpresa, $idPlan)')
        && str_contains($capabilities, "'COMPLEMENTO'")
        && str_contains($saas, 'PRECONTABILIDAD_EXCEPCION_INTERNA_REQUERIDA'),
    'Las capacidades deben reconocer complementos y Precontabilidad debe pasar por excepcion interna.'
);

expectCommercialComplement(
    str_contains($modules, 'EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA')
        && str_contains($permissions, 'EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA')
        && str_contains($routes, '/complementos/elegibles')
        && str_contains($routes, '/excepciones-internas/'),
    'Las rutas antiguas deben quedar obsoletas y la nueva API debe ser interna.'
);

expectCommercialComplement(
    str_contains($migration, 'Transicion no destructiva')
        && !str_contains($migration, 'INSERT INTO admin.empresa_modulo')
        && !str_contains($migration, 'INSERT INTO admin.empresa_permiso')
        && !str_contains($migration, 'UPDATE admin.empresa_modulo')
        && !str_contains($migration, 'UPDATE admin.empresa_permiso'),
    'Instalar el catalogo no puede sobrescribir ni borrar asignaciones manuales existentes.'
);

expectCommercialComplement(
    str_contains($migration, 'No se infiere ni crea automaticamente PRECONTABILIDAD_INTERNA')
        && str_contains($catalog, "'SOLO_INTERNO'")
        && str_contains($entitlements, "'EXCEPCION_INTERNA'"),
    'Precontabilidad solo debe ser efectiva en modo planes mediante su excepcion interna explicita.'
);

$previousMode = $_ENV['ENTITLEMENTS_MODE'] ?? null;
unset($_ENV['ENTITLEMENTS_MODE']);
$modeService = new \PosAdmin\Service\CommercialComplementService();
expectCommercialComplement(
    !$modeService->plansMode(),
    'Sin ENTITLEMENTS_MODE el sistema debe continuar en modo legacy.'
);
$_ENV['ENTITLEMENTS_MODE'] = 'plans';
expectCommercialComplement(
    $modeService->plansMode()
        && str_contains($modules, 'EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA')
        && str_contains($permissions, 'EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA'),
    'Solo ENTITLEMENTS_MODE=plans puede bloquear la edicion directa heredada.'
);

if ($previousMode === null) {
    unset($_ENV['ENTITLEMENTS_MODE']);
} else {
    $_ENV['ENTITLEMENTS_MODE'] = $previousMode;
}

echo "Complementos comerciales: OK\n";

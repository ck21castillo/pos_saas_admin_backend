<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\SaasCapabilityReconciliationService;
use PosAdmin\Service\SaasCapabilityService;

function expectCapabilityReconciliation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/src/Service/SaasCapabilityReconciliationService.php');
$capabilities = (string)file_get_contents($root . '/src/Service/SaasCapabilityService.php');
$command = (string)file_get_contents($root . '/bin/reconcile_saas_capabilities.php');

// Fixtures puros: representan los casos reales sin conectar a bersano_control
// ni a un tenant compartido.
$capabilityFixtures = [
    2 => [
        'actual' => ['PRECONTABILIDAD' => true],
        'esperado' => ['PRECONTABILIDAD' => true],
    ],
    3 => [
        'actual' => ['EXPORTACION_CONTABLE' => true, 'PRECONTABILIDAD' => true],
        'esperado' => ['EXPORTACION_CONTABLE' => false, 'PRECONTABILIDAD' => false],
    ],
    6 => [
        'actual' => ['EXPORTACION_CONTABLE' => true, 'PRECONTABILIDAD' => true],
        'esperado' => ['EXPORTACION_CONTABLE' => false, 'PRECONTABILIDAD' => false],
    ],
];

expectCapabilityReconciliation(
    SaasCapabilityReconciliationService::diff(
        $capabilityFixtures[2]['actual'],
        $capabilityFixtures[2]['esperado']
    ) === [],
    'La excepcion interna efectiva de la empresa 2 no debe generar cambios.'
);

foreach ([3, 6] as $idEmpresa) {
    expectCapabilityReconciliation(
        SaasCapabilityReconciliationService::diff(
            $capabilityFixtures[$idEmpresa]['actual'],
            $capabilityFixtures[$idEmpresa]['esperado']
        ) === [
            [
                'codigo_capacidad' => 'EXPORTACION_CONTABLE',
                'actual' => true,
                'esperado' => false,
            ],
            [
                'codigo_capacidad' => 'PRECONTABILIDAD',
                'actual' => true,
                'esperado' => false,
            ],
        ],
        "La empresa {$idEmpresa} debe reconciliar Exportacion contable y Precontabilidad a false antes de sincronizar su tenant."
    );
}

expectCapabilityReconciliation(
    SaasCapabilityReconciliationService::diff(
        ['PRECONTABILIDAD' => false],
        ['PRECONTABILIDAD' => false]
    ) === [],
    'Una segunda ejecucion con el valor ya reconciliado no puede volver a escribir ni sincronizar.'
);

expectCapabilityReconciliation(
    str_contains($service, 'applyEffectiveCapabilities($pdo, $idEmpresa)')
        && str_contains($service, 'SAAS_CAPACIDADES_RECONCILIADAS_CLI')
        && str_contains($service, 'pos_saas.empresa_capacidad')
        && !str_contains($service, 'admin.empresa_modulo')
        && !str_contains($service, 'admin.empresa_permiso')
        && !str_contains($service, 'INSERT INTO admin.saas_suscripcion')
        && !str_contains($service, 'UPDATE admin.saas_suscripcion'),
    'La reconciliacion debe limitarse al espejo de capacidades y no alterar recursos comerciales.'
);

expectCapabilityReconciliation(
    str_contains($capabilities, 'EFFECTIVE_SUBSCRIPTION_STATES')
        && str_contains($service, 'SaasCapabilityService::effectiveSubscriptionStates()')
        && str_contains($command, "['dry-run', 'apply', 'actor::']")
        && str_contains($command, 'enqueueBusinessConfig($pdo, $idEmpresa)')
        && str_contains($command, 'processBusinessConfigForCompany($idEmpresa)')
        && str_contains($command, '$pdo->commit()'),
    'La CLI debe usar estados canonicos, outbox BUSINESS_CONFIG y sincronizacion posterior al commit.'
);

$subscriptionSqlMethod = new ReflectionMethod(SaasCapabilityService::class, 'effectiveSubscriptionSql');
$subscriptionSql = $subscriptionSqlMethod->invoke(null, [':state_0', ':state_1']);
$literalBackslashN = chr(92) . 'n';
expectCapabilityReconciliation(
    !str_contains($subscriptionSql, $literalBackslashN)
        && str_contains($subscriptionSql, 'SELECT id_plan')
        && str_contains($subscriptionSql, ':state_0, :state_1')
        && str_contains($subscriptionSql, 'FROM admin.saas_suscripcion'),
    'El SQL preparado por companyCapabilities debe contener saltos de linea reales, no la secuencia literal \\n.'
);

echo "Reconciliacion de capacidades SaaS: OK\n";

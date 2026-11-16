<?php

declare(strict_types=1);

function expectCommercialSync(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/src/Controller/AdminSaasController.php');
$tenantSync = (string)file_get_contents($root . '/src/Service/AdminTenantSyncService.php');

foreach (['saveCompanySubscription', 'saveCompanyCapabilityException', 'registerPayment'] as $method) {
    $start = strpos($controller, 'public function ' . $method);
    $next = strpos($controller, 'public function ', $start + 1);
    $source = substr($controller, $start, ($next === false ? strlen($controller) : $next) - $start);
    expectCommercialSync(
        $start !== false
            && str_contains($source, '$this->transaction(')
            && str_contains($source, '}, 200, $idEmpresa)') || ($method === 'registerPayment' && str_contains($source, '}, 201, $idEmpresa)')),
        "{$method} debe usar la transaccion comercial con sincronizacion de tenant."
    );
}

$transactionStart = strpos($controller, 'private function transaction');
$transaction = substr($controller, $transactionStart);
$enqueue = strpos($transaction, 'enqueueBusinessConfig($pdo, $syncEmpresaId)');
$commit = strpos($transaction, '$pdo->commit()');
$process = strpos($transaction, 'processBusinessConfigForCompany($syncEmpresaId)');

expectCommercialSync(
    $transactionStart !== false && $enqueue !== false && $commit !== false && $process !== false
        && $enqueue < $commit && $commit < $process,
    'La outbox debe encolarse antes del commit y procesarse solo despues del commit.'
);

expectCommercialSync(
    str_contains($tenantSync, '$this->syncCompanyCapabilities($control, $tenant, $companyId);'),
    'La sincronizacion BUSINESS_CONFIG debe copiar empresa_capacidad al tenant.'
);

echo "Sincronizacion comercial de capacidades: OK\n";

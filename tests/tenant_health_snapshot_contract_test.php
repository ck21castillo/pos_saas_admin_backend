<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\TenantHealthService;

function expectHealth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function snapshotListItem(TenantHealthService $service, array $row, bool $available): array
{
    $method = new ReflectionMethod($service, 'snapshotListItem');
    $method->setAccessible(true);
    /** @var array<string, mixed> $item */
    $item = $method->invoke($service, $row, $available);
    return $item;
}

$row = [
    'id_empresa' => 9,
    'empresa_nombre' => 'Empresa de prueba',
    'empresa_estado' => 1,
    'tipo_negocio' => 'GENERAL',
    'db_schema' => 'pos_saas',
    'snapshot_checked_at' => null,
    'snapshot_payload' => null,
    'snapshot_health_status' => null,
    'snapshot_connection_ms' => null,
    'snapshot_check_ms' => null,
];

$service = new TenantHealthService();
$pending = snapshotListItem($service, $row, false);
expectHealth(($pending['health_status'] ?? null) === 'PENDING', 'LISTA_SIN_SNAPSHOT_DEBE_QUEDAR_PENDIENTE');
expectHealth(($pending['inspection']['state'] ?? null) === 'PENDING', 'ESTADO_PENDIENTE_INVALIDO');

$freshRow = $row;
$freshRow['snapshot_checked_at'] = gmdate('c');
$freshRow['snapshot_health_status'] = 'OK';
$freshRow['snapshot_connection_ms'] = 12;
$freshRow['snapshot_check_ms'] = 38;
$freshRow['snapshot_payload'] = json_encode([
    'health_status' => 'OK',
    'recent' => ['ventas_30d' => 3],
    'warnings' => [],
    'errors' => [],
]);
$fresh = snapshotListItem($service, $freshRow, true);
expectHealth(($fresh['health_status'] ?? null) === 'OK', 'SNAPSHOT_VIGENTE_DEBE_CONSERVAR_SALUD');
expectHealth(($fresh['inspection']['state'] ?? null) === 'FRESH', 'SNAPSHOT_VIGENTE_INVALIDO');
expectHealth(($fresh['recent']['ventas_30d'] ?? null) === 3, 'PAYLOAD_DE_SNAPSHOT_NO_SE_LEE');

$staleRow = $freshRow;
$staleRow['snapshot_checked_at'] = gmdate('c', time() - 901);
$stale = snapshotListItem($service, $staleRow, true);
expectHealth(($stale['inspection']['state'] ?? null) === 'STALE', 'SNAPSHOT_VENCIDO_NO_SE_MARCO');

echo "Tenant health snapshots: OK\n";

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

/** @return list<string> */
function companyMirrorDifferences(TenantHealthService $service, array $control, array $tenant): array
{
    $method = new ReflectionMethod($service, 'companyMirrorDifferences');
    $method->setAccessible(true);
    /** @var list<string> $differences */
    $differences = $method->invoke($service, $control, $tenant);
    return $differences;
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
$sameOperationalProfile = companyMirrorDifferences($service, [
    'nombre' => 'Empresa de prueba',
    'estado' => 1,
    'tipo_negocio' => 'FERRETERIA',
], [
    'nombre' => 'Empresa de prueba',
    'estado' => 1,
    'tipo_negocio' => 'GENERAL',
]);
expectHealth($sameOperationalProfile === [], 'CATEGORIA_HISTORICA_Y_PERFIL_GENERAL_NO_DEBEN_GENERAR_ALERTA');

$differentOperationalProfile = companyMirrorDifferences($service, [
    'nombre' => 'Empresa de prueba',
    'estado' => 1,
    'tipo_negocio' => 'DROGUERIA',
], [
    'nombre' => 'Empresa de prueba',
    'estado' => 1,
    'tipo_negocio' => 'GENERAL',
]);
expectHealth(
    $differentOperationalProfile === ['tipo_negocio'],
    'PERFILES_OPERATIVOS_DISTINTOS_DEBEN_GENERAR_ALERTA'
);

$literalDifferences = companyMirrorDifferences($service, [
    'nombre' => 'Empresa de prueba',
    'estado' => 1,
    'tipo_negocio' => 'FERRETERIA',
], [
    'nombre' => 'Empresa cambiada',
    'estado' => 0,
    'tipo_negocio' => 'GENERAL',
]);
expectHealth(
    $literalDifferences === ['nombre', 'estado'],
    'NOMBRE_Y_ESTADO_DEBEN_SEGUIR_COMPARANDOSE_LITERALMENTE'
);

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

$historicTypeRow = $row;
$historicTypeRow['tipo_negocio'] = 'FERRETERIA';
$historicType = snapshotListItem($service, $historicTypeRow, false);
expectHealth(($historicType['tipo_negocio'] ?? null) === 'GENERAL', 'SALIDA_DE_SALUD_DEBE_EXPONER_PERFIL_CANONICO');

$staleRow = $freshRow;
$staleRow['snapshot_checked_at'] = gmdate('c', time() - 901);
$stale = snapshotListItem($service, $staleRow, true);
expectHealth(($stale['inspection']['state'] ?? null) === 'STALE', 'SNAPSHOT_VENCIDO_NO_SE_MARCO');

echo "Tenant health snapshots: OK\n";

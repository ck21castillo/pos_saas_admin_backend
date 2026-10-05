<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\TenantSyncOutboxWorker;

// Prueba unitaria: no requiere ni debe abrir una conexion a base de datos.
function expectOutbox(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$receivedLimit = null;
$worker = new TenantSyncOutboxWorker(static function (int $limit) use (&$receivedLimit): array {
    $receivedLimit = $limit;
    return [
        ['id_empresa' => 11, 'estado' => 'SINCRONIZADO'],
        ['id_empresa' => 12, 'estado' => 'PENDIENTE'],
        [
            'id_empresa' => 13,
            'estado' => 'PENDIENTE',
            'ultimo_error' => 'password=secreto username=admin postgres://user:clave@host/base',
        ],
    ];
});

expectOutbox(TenantSyncOutboxWorker::normalizeLimit(null) === 50, 'LIMIT_POR_DEFECTO_INVALIDO');
expectOutbox(TenantSyncOutboxWorker::normalizeLimit('0') === 1, 'LIMIT_MINIMO_INVALIDO');
expectOutbox(TenantSyncOutboxWorker::normalizeLimit('200') === 200, 'LIMIT_MAXIMO_INVALIDO');
expectOutbox(TenantSyncOutboxWorker::normalizeLimit('999') === 200, 'LIMIT_SUPERIOR_NO_ACOTADO');

$invalidLimit = false;
try {
    TenantSyncOutboxWorker::normalizeLimit('abc');
} catch (InvalidArgumentException $e) {
    $invalidLimit = $e->getMessage() === 'LIMIT_INVALID';
}
expectOutbox($invalidLimit, 'LIMIT_INVALIDO_NO_RECHAZADO');

$summary = $worker->run(999);
expectOutbox($receivedLimit === 200, 'WORKER_NO_RECIBIO_LIMITE_ACOTADO');
expectOutbox((int)$summary['total_procesadas'] === 3, 'TOTAL_PROCESADAS_INVALIDO');
expectOutbox((int)$summary['sincronizadas'] === 1, 'TOTAL_SINCRONIZADAS_INVALIDO');
expectOutbox((int)$summary['pendientes'] === 2, 'TOTAL_PENDIENTES_INVALIDO');
expectOutbox((int)$summary['fallidas'] === 1, 'TOTAL_FALLIDAS_INVALIDO');

$output = $worker->cronOutput($summary);
expectOutbox(str_contains($output, 'empresa=13'), 'ERROR_POR_EMPRESA_NO_REPORTADO');
expectOutbox(!str_contains($output, 'secreto'), 'PASSWORD_EXPUESTO_EN_CRON');
expectOutbox(!str_contains($output, 'admin'), 'USUARIO_EXPUESTO_EN_CRON');
expectOutbox(!str_contains($output, 'clave@host'), 'DSN_EXPUESTO_EN_CRON');
expectOutbox(str_contains($output, '[redacted]'), 'SANITIZACION_NO_APLICADA');

echo "Tenant sync outbox worker unit: OK\n";

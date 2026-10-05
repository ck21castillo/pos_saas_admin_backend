<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\TenantSyncOutboxWorker;

try {
    $options = getopt('', ['limit::']);
    $limit = TenantSyncOutboxWorker::normalizeLimit(isset($options['limit']) ? (string)$options['limit'] : null);
    $worker = new TenantSyncOutboxWorker();
    $summary = $worker->run($limit);
    fwrite(STDOUT, $worker->cronOutput($summary) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'tenant_sync_outbox_fatal error=WORKER_EXECUTION_FAILED' . PHP_EOL);
    exit(1);
}

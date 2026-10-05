<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\TenantHealthService;

$options = getopt('', ['limit::', 'deep']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 20;
$deep = array_key_exists('deep', $options);

try {
    $result = (new TenantHealthService())->refreshDue($limit, $deep);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'TENANT_HEALTH_REFRESH_FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

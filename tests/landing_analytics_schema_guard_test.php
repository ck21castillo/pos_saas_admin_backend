<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\LandingAnalyticsSchemaGuard;

function expectLandingAnalytics(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$available = new LandingAnalyticsSchemaGuard(static fn(): bool => true);
$available->assertAvailable();

$missing = new LandingAnalyticsSchemaGuard(static fn(): bool => false);
try {
    $missing->assertAvailable();
    throw new RuntimeException('SCHEMA_FALTANTE_NO_FUE_DETECTADO');
} catch (RuntimeException $error) {
    expectLandingAnalytics(
        $error->getMessage() === 'LANDING_ANALYTICS_SCHEMA_MISSING',
        'CODIGO_DE_ESQUEMA_FALTANTE_INVALIDO'
    );
}

$controller = file_get_contents(dirname(__DIR__) . '/src/Controller/LandingAnalyticsController.php');
expectLandingAnalytics(is_string($controller), 'NO_SE_PUDO_LEER_CONTROLADOR');
expectLandingAnalytics(
    !preg_match('/\\b(CREATE|ALTER|DROP|TRUNCATE|REINDEX|VACUUM)\\b/i', $controller),
    'CONTROLADOR_AUN_CONTIENE_DDL_RUNTIME'
);

echo "Landing analytics schema guard: OK\n";

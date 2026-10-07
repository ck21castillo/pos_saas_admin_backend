<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Service\AdminSaasService;

function expectPublicBenefitBoolean(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<array<string, mixed>> */
function normalizePublicBenefits(AdminSaasService $service, array $benefits): array
{
    $method = new ReflectionMethod($service, 'normalizePublicBenefits');
    $method->setAccessible(true);
    /** @var list<array<string, mixed>> $normalized */
    $normalized = $method->invoke($service, $benefits);
    return $normalized;
}

$service = new AdminSaasService();
$normalized = normalizePublicBenefits($service, [
    ['titulo' => 'Boolean false', 'incluido' => false],
    ['titulo' => 'Postgres false', 'incluido' => 'f'],
    ['titulo' => 'String false', 'incluido' => 'false'],
    ['titulo' => 'Zero', 'incluido' => 0],
    ['titulo' => 'Enabled', 'incluido' => true],
]);

foreach ([0, 1, 2, 3] as $index) {
    expectPublicBenefitBoolean(
        ($normalized[$index]['incluido'] ?? null) === false,
        'BENEFICIO_APAGADO_NO_SE_NORMALIZO_EN_INDICE_' . $index
    );
}
expectPublicBenefitBoolean(
    ($normalized[4]['incluido'] ?? null) === true,
    'BENEFICIO_ACTIVO_NO_SE_NORMALIZO'
);

echo "SaaS public benefit booleans: OK\n";

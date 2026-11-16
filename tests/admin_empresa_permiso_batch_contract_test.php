<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/src/Controller/AdminEmpresaPermisoController.php');
$capabilities = (string)file_get_contents($root . '/src/Service/SaasCapabilityService.php');

function expectPermissionBatch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    'private const MAX_SAVE_ITEMS = 500',
    'assertPermissionsCanBeEnabled($pdo, $idEmpresa, $enabledPermissionIds)',
    'jsonb_to_recordset(CAST(:items AS jsonb))',
    'ANY(CAST(:ids AS bigint[]))',
    'enabled requerido para id_permiso',
    'enabled invalido para id_permiso',
    'Un solo round trip aun cuando la interfaz envie el catalogo completo.',
] as $required) {
    expectPermissionBatch(str_contains($controller, $required), "Falta optimizacion de guardado masivo: {$required}");
}

expectPermissionBatch(
    !str_contains($controller, '$enabled = true;'),
    'Un enabled invalido no puede convertirse silenciosamente en habilitado.'
);

foreach ([
    'public function assertPermissionsCanBeEnabled',
    'WHERE id_permiso = ANY(CAST(:permission_ids AS bigint[]))',
    'foreach ($this->companyCapabilities($pdo, $idEmpresa, false) as $row)',
] as $required) {
    expectPermissionBatch(str_contains($capabilities, $required), "Falta validacion SaaS agrupada: {$required}");
}

echo "Contrato de guardado masivo de permisos: OK\n";

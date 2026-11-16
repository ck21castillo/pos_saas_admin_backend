<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Controller/AdminEmpresaModuloController.php');
if (!is_string($source)) {
    throw new RuntimeException('No fue posible leer AdminEmpresaModuloController.');
}

if (!str_contains($source, "'detalle' => 'SINCRONIZACION_EN_COLA'")) {
    throw new RuntimeException('El guardado de modulos debe devolver sincronizacion en cola.');
}

if (str_contains($source, "'sync' => \$sync->processBusinessConfigForCompany(\$idEmpresa)")) {
    throw new RuntimeException('El guardado de modulos no debe sincronizar el tenant dentro de la respuesta HTTP.');
}

echo "Contrato outbox de modulos por empresa: OK\n";

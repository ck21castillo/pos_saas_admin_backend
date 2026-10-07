<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PosAdmin\Service\AdminInventoryImportPreviewService;
use PosAdmin\Service\AdminInventoryImportTemplateService;

function expectInventorySecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class)) {
    echo "Inventory XLSX security: SKIPPED (ZipArchive/XMLReader unavailable)\n";
    exit(0);
}

function createInventoryXlsx(string $path, string $sheet, ?string $sharedStrings = null): void
{
    $zip = new ZipArchive();
    expectInventorySecurity($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'No se pudo crear XLSX temporal.');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    if ($sharedStrings !== null) {
        $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
    }
    $zip->close();
}

$normal = tempnam(sys_get_temp_dir(), 'inventory_xlsx_');
$bomb = tempnam(sys_get_temp_dir(), 'inventory_xlsx_');
if ($normal === false || $bomb === false) {
    throw new RuntimeException('No se pudo crear archivo temporal.');
}

try {
    createInventoryXlsx($normal, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData>
  <x:row r="1"><x:c r="A1" t="s"><x:v>0</x:v></x:c><x:c r="B1" t="s"><x:v>1</x:v></x:c></x:row>
  <x:row r="2"><x:c r="A2" t="s"><x:v>2</x:v></x:c><x:c r="B2"><x:v>100</x:v></x:c></x:row>
</x:sheetData></x:worksheet>
XML, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:sst xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <x:si><x:t>nombre</x:t></x:si><x:si><x:t>costo_unitario</x:t></x:si><x:si><x:t>Producto prueba</x:t></x:si>
</x:sst>
XML);

    $service = new AdminInventoryImportPreviewService();
    $read = new ReflectionMethod($service, 'readXlsx');
    $read->setAccessible(true);
    [$headers, $rows] = $read->invoke($service, $normal);
    expectInventorySecurity($headers === ['nombre', 'costo_unitario'], 'Debe leer XLSX con prefijos XML.');
    expectInventorySecurity(($rows[2]['nombre'] ?? '') === 'Producto prueba', 'Debe conservar celdas de textos compartidos.');

    $template = (new AdminInventoryImportTemplateService())->buildXlsx([
        'id_empresa' => 1,
        'tipo_negocio' => 'GENERAL',
        'capacidades' => [],
    ]);
    file_put_contents($normal, $template['content']);
    [$headers, $rows] = $read->invoke($service, $normal);
    expectInventorySecurity(in_array('utilidad_porcentaje', $headers, true), 'Debe leer la plantilla generada por el panel.');
    expectInventorySecurity(count($rows) === 20000, 'La plantilla de 20.000 filas debe seguir siendo legible.');

    createInventoryXlsx($bomb, '<worksheet><sheetData><row r="1"><c r="A1"><v>' . str_repeat('A', 1024 * 1024) . '</v></c></row></sheetData></worksheet>');
    try {
        $read->invoke($service, $bomb);
        throw new RuntimeException('Debe rechazar compresion excesiva.');
    } catch (RuntimeException $e) {
        expectInventorySecurity($e->getMessage() === 'El archivo Excel tiene una compresion no permitida.', 'Debe detectar expansion ZIP sospechosa.');
    }
} finally {
    @unlink($normal);
    @unlink($bomb);
}

echo "Inventory XLSX security: OK\n";

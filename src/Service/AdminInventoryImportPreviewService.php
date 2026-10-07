<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;
use PosAdmin\Core\Database;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class AdminInventoryImportPreviewService
{
    private const REQUIRED_HEADERS = [
        'nombre',
        'costo_unitario',
        'utilidad_porcentaje',
        'precio',
        'stock',
    ];

    private const PREVIEW_LIMIT = 100;
    private const MAX_ROWS = 20000;
    private const MAX_UPLOAD_MB = 30;
    private const MAX_ZIP_ENTRIES = 300;
    private const MAX_ZIP_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;
    private const MAX_SHEET_XML_BYTES = 48 * 1024 * 1024;
    private const MAX_SHARED_STRINGS_BYTES = 24 * 1024 * 1024;
    private const MAX_ZIP_COMPRESSION_RATIO = 100;
    private const MAX_COLUMNS = 64;
    private const MAX_SHARED_STRINGS = 350000;
    private const MAX_CELL_TEXT_BYTES = 16384;

    public function preview(PDO $control, int $empresaId, array $upload): array
    {
        $empresa = BusinessConfigService::getEmpresa($control, $empresaId);
        if (!$empresa) {
            return [
                'ok' => false,
                'error' => 'EMPRESA_NOT_FOUND',
                'message' => 'Empresa no existe.',
            ];
        }

        $this->assertValidUpload($upload);

        $config = BusinessConfigService::getConfig($control, $empresaId);
        [$headers, $rows] = $this->readXlsx((string)$upload['tmp_name']);

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missing !== []) {
            return [
                'ok' => true,
                'id_empresa' => $empresaId,
                'filename' => (string)($upload['name'] ?? ''),
                'summary' => [
                    'rows_read' => 0,
                    'valid_rows' => 0,
                    'rows_with_errors' => 0,
                    'errors' => count($missing),
                    'warnings' => 0,
                    'can_confirm' => false,
                ],
                'errors' => array_map(static fn(string $h): array => [
                    'row' => 1,
                    'field' => $h,
                    'message' => 'Falta la columna obligatoria "' . $h . '".',
                ], $missing),
                'warnings' => [],
                'items' => [],
            ];
        }

        $tenant = null;
        $globalWarnings = [];
        try {
            $tenant = $this->tenantConnection($control, $empresaId);
        } catch (RuntimeException $e) {
            $globalWarnings[] = [
                'row' => null,
                'field' => 'tenant',
                'message' => $e->getMessage(),
            ];
        }

        $items = [];
        $productSkus = [];
        $productBarcodes = [];
        $presentationSkus = [];
        $presentationBarcodes = [];
        $rowsRead = 0;
        $hasWeight = $this->capabilityEnabled($config, BusinessConfigService::CAP_PRODUCTOS_PESO);
        $hasPresentations = $this->capabilityEnabled($config, BusinessConfigService::CAP_PRODUCTOS_PRESENTACION);
        $hasLots = $this->capabilityEnabled($config, BusinessConfigService::CAP_LOTES_VENCIMIENTOS);
        $marginStorageMax = $tenant instanceof PDO ? $this->numericColumnMax($tenant, 'producto', 'margen_porcentaje') : null;
        $taxRateStorageMax = $tenant instanceof PDO ? $this->numericColumnMax($tenant, 'impuesto', 'tasa_impuesto') : null;

        foreach ($rows as $rowNumber => $row) {
            if ($this->isGuideOrEmptyRow($row)) {
                continue;
            }

            $rowsRead++;
            if ($rowsRead > self::MAX_ROWS) {
                $items[$rowNumber] = [
                    'row' => $rowNumber,
                    'status' => 'ERROR',
                    'nombre' => '',
                    'sku' => '',
                    'codigo_barras' => '',
                    'precio' => '',
                    'stock' => '',
                    'tipo_cantidad' => '',
                    'errors' => ['El archivo supera el maximo de ' . self::MAX_ROWS . ' filas.'],
                    'warnings' => [],
                ];
                break;
            }

            $item = [
                'row' => $rowNumber,
                'status' => 'OK',
                'nombre' => trim((string)($row['nombre'] ?? '')),
                'sku' => trim((string)($row['sku'] ?? '')),
                'codigo_barras' => trim((string)($row['codigo_barras'] ?? '')),
                'precio' => trim((string)($row['precio'] ?? '')),
                'utilidad_porcentaje' => trim((string)($row['utilidad_porcentaje'] ?? '')),
                'utilidad_porcentaje_calculada' => false,
                'stock' => trim((string)($row['stock'] ?? '')),
                'tipo_cantidad' => $this->normalizeTipoCantidad((string)($row['tipo_cantidad'] ?? '')),
                'errors' => [],
                'warnings' => [],
            ];

            foreach (self::REQUIRED_HEADERS as $field) {
                if ($field === 'utilidad_porcentaje' && trim((string)($row[$field] ?? '')) === '') {
                    continue;
                }
                if (trim((string)($row[$field] ?? '')) === '') {
                    $item['errors'][] = 'El campo ' . $field . ' es obligatorio.';
                }
            }

            $this->validateNumberField($row, 'costo_unitario', 'Costo unitario', $item, false);
            $this->validateNumberField($row, 'precio', 'Precio', $item, false);
            $utilidadRaw = trim((string)($row['utilidad_porcentaje'] ?? ''));
            if ($utilidadRaw !== '') {
                $utilidad = $this->parseNumber($utilidadRaw);
                if ($utilidad === null) {
                    $item['errors'][] = 'Utilidad % debe ser numerico.';
                } elseif ($utilidad <= 0) {
                    $item['errors'][] = 'Utilidad % debe ser mayor a 0.';
                } else {
                    $this->validatePercentageStorageRange($utilidad, 'Utilidad %', $item, $marginStorageMax);
                }
            } else {
                $calculatedMargin = $this->calculateMarginForPreview($row);
                if ($calculatedMargin === null) {
                    $item['errors'][] = 'Utilidad % debe informarse o poder calcularse desde costo_unitario y precio.';
                } elseif ($calculatedMargin <= 0) {
                    $item['errors'][] = 'Utilidad % calculada debe ser mayor a 0.';
                } else {
                    $this->validatePercentageStorageRange($calculatedMargin, 'Utilidad % calculada', $item, $marginStorageMax);
                    $item['utilidad_porcentaje'] = $this->formatDecimal($calculatedMargin);
                    $item['utilidad_porcentaje_calculada'] = true;
                }
            }
            $stock = $this->parseNumber((string)($row['stock'] ?? ''));
            if ($stock === null || $stock <= 0) {
                $item['errors'][] = 'Stock debe ser un numero mayor a 0.';
            } elseif ($item['tipo_cantidad'] === 'PESO') {
                if (!$hasWeight) {
                    $item['errors'][] = 'La empresa no tiene activa la capacidad PRODUCTOS_PESO.';
                }
                if (!$this->hasMaxDecimals((string)($row['stock'] ?? ''), 3)) {
                    $item['errors'][] = 'Stock por peso acepta maximo 3 decimales.';
                }
            } elseif (!$this->isIntegerNumber((string)($row['stock'] ?? ''))) {
                $item['errors'][] = 'Stock de producto por unidad debe ser entero.';
            }

            $tipoRaw = strtoupper(trim((string)($row['tipo_cantidad'] ?? '')));
            if ($tipoRaw !== '' && !in_array($item['tipo_cantidad'], ['UNIDAD', 'PESO'], true)) {
                $item['errors'][] = 'Tipo de cantidad invalido. Usa UNIDAD o PESO.';
            }

            if (trim((string)($row['fecha_vencimiento'] ?? '')) !== '') {
                if (!$hasLots) {
                    $item['warnings'][] = 'La empresa no tiene LOTES_VENCIMIENTOS activo; la fecha se ignoraria.';
                }
                $date = $this->normalizeDate((string)$row['fecha_vencimiento']);
                if ($date === null) {
                    $item['errors'][] = 'Fecha de vencimiento invalida. Usa AAAA-MM-DD.';
                }
            }

            $taxRateRaw = trim((string)($row['impuesto_tasa'] ?? ''));
            if ($taxRateRaw !== '') {
                $taxRate = $this->parseNumber($taxRateRaw);
                if ($taxRate === null) {
                    $item['errors'][] = 'Impuesto tasa debe ser numerico.';
                } else {
                    $this->validatePercentageStorageRange($taxRate, 'Impuesto tasa', $item, $taxRateStorageMax);
                }
            }

            $usesPresentations = $this->normalizeYesNo((string)($row['usa_presentaciones'] ?? ''));
            if ($usesPresentations === null && trim((string)($row['usa_presentaciones'] ?? '')) !== '') {
                $item['errors'][] = 'Usa presentaciones debe ser SI o NO.';
            }
            if ($usesPresentations === true && !$hasPresentations) {
                $item['errors'][] = 'La empresa no tiene activa la capacidad PRODUCTOS_PRESENTACION.';
            }

            $presentations = $this->extractPresentations($row);
            if ($usesPresentations === true && $presentations === []) {
                $item['warnings'][] = 'Se crearia solo la presentacion Unidad x 1. Agrega blister/caja si aplica.';
            }
            foreach ($presentations as $idx => $presentation) {
                if ($presentation['nombre'] === '') {
                    $item['errors'][] = 'Presentacion ' . $idx . ': nombre requerido si llenas datos de presentacion.';
                }
                if ($presentation['unidades_base'] === null || $presentation['unidades_base'] <= 0 || floor($presentation['unidades_base']) !== $presentation['unidades_base']) {
                    $item['errors'][] = 'Presentacion ' . $idx . ': unidades base debe ser entero mayor a 0.';
                }
                if ($presentation['precio'] !== null && $presentation['precio'] <= 0) {
                    $item['errors'][] = 'Presentacion ' . $idx . ': precio debe ser mayor a 0.';
                }
                if ($presentation['sku'] !== '') {
                    $presentationSkus[$this->normalizeKey($presentation['sku'])][] = $rowNumber;
                }
                if ($presentation['codigo_barras'] !== '') {
                    $presentationBarcodes[$presentation['codigo_barras']][] = $rowNumber;
                }
            }

            if ($this->looksScientific($item['codigo_barras'])) {
                $item['warnings'][] = 'El codigo de barras parece estar en notacion cientifica. En Excel debe quedar como texto.';
            }

            if ($item['sku'] !== '') {
                $productSkus[$this->normalizeKey($item['sku'])][] = $rowNumber;
            }
            if ($item['codigo_barras'] !== '') {
                $productBarcodes[$item['codigo_barras']][] = $rowNumber;
            }

            $items[$rowNumber] = $item;
        }

        $this->markFileDuplicates($items, $productSkus, 'sku', 'SKU duplicado dentro del archivo.');
        $this->markFileDuplicates($items, $productBarcodes, 'codigo_barras', 'Codigo de barras duplicado dentro del archivo.');
        $this->markFileDuplicates($items, $presentationSkus, 'sku_presentacion', 'SKU de presentacion duplicado dentro del archivo.');
        $this->markFileDuplicates($items, $presentationBarcodes, 'codigo_barras_presentacion', 'Codigo de barras de presentacion duplicado dentro del archivo.');

        if ($tenant instanceof PDO) {
            $this->markTenantDuplicates($tenant, $empresaId, $items, array_keys($productSkus), 'sku_producto', 'SKU ya existe en productos.');
            $this->markTenantDuplicates($tenant, $empresaId, $items, array_keys($productBarcodes), 'codigo_barras_producto', 'Codigo de barras ya existe en productos.');
            $this->markPresentationTenantDuplicates($tenant, $empresaId, $items, $presentationSkus, 'sku_presentacion', 'SKU ya existe en presentaciones.');
            $this->markPresentationTenantDuplicates($tenant, $empresaId, $items, $presentationBarcodes, 'codigo_barras', 'Codigo de barras ya existe en presentaciones.');
        }

        $flatErrors = [];
        $flatWarnings = $globalWarnings;
        $validRows = 0;
        $rowsWithErrors = 0;
        foreach ($items as &$item) {
            if ($item['errors'] !== []) {
                $item['status'] = 'ERROR';
                $rowsWithErrors++;
                foreach ($item['errors'] as $message) {
                    $flatErrors[] = ['row' => $item['row'], 'field' => null, 'message' => $message];
                }
            } else {
                $validRows++;
            }
            foreach ($item['warnings'] as $message) {
                $flatWarnings[] = ['row' => $item['row'], 'field' => null, 'message' => $message];
            }
        }
        unset($item);

        return [
            'ok' => true,
            'id_empresa' => $empresaId,
            'filename' => (string)($upload['name'] ?? ''),
            'summary' => [
                'rows_read' => $rowsRead,
                'valid_rows' => $validRows,
                'rows_with_errors' => $rowsWithErrors,
                'errors' => count($flatErrors),
                'warnings' => count($flatWarnings),
                'can_confirm' => $rowsRead > 0 && $rowsWithErrors === 0,
            ],
            'errors' => array_slice($flatErrors, 0, 200),
            'warnings' => array_slice($flatWarnings, 0, 200),
            'items' => array_slice(array_values($items), 0, self::PREVIEW_LIMIT),
        ];
    }

    /**
     * @return array{0: array<int,string>, 1: array<int,array<string,string>>}
     */
    public function readRowsForImport(array $upload): array
    {
        $this->assertValidUpload($upload);
        [$headers, $rows] = $this->readXlsx((string)$upload['tmp_name']);

        $filtered = [];
        foreach ($rows as $rowNumber => $row) {
            if ($this->isGuideOrEmptyRow($row)) {
                continue;
            }
            $filtered[$rowNumber] = $row;
        }

        return [$headers, $filtered];
    }

    private function assertValidUpload(array $upload): void
    {
        if (!isset($upload['tmp_name']) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se recibio un archivo valido.');
        }
        $name = strtolower((string)($upload['name'] ?? ''));
        if (!str_ends_with($name, '.xlsx')) {
            throw new RuntimeException('Por ahora la importacion acepta archivos .xlsx.');
        }
        if ((int)($upload['size'] ?? 0) > self::MAX_UPLOAD_MB * 1024 * 1024) {
            throw new RuntimeException('El archivo supera el maximo permitido de ' . self::MAX_UPLOAD_MB . ' MB.');
        }
        if (!is_file((string)$upload['tmp_name'])) {
            throw new RuntimeException('No se pudo leer el archivo temporal.');
        }
        if ((int)($upload['size'] ?? 0) <= 0) {
            throw new RuntimeException('El archivo esta vacio.');
        }
    }

    /**
     * @return array{0: array<int,string>, 1: array<int,array<string,string>>}
     */
    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la extension ZipArchive.');
        }
        if (!class_exists(XMLReader::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la extension XMLReader.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Excel.');
        }

        try {
            $this->assertSafeXlsxArchive($zip);
            $sharedStrings = $this->readSharedStrings($path);
            return $this->readWorksheet($path, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int,string>
     */
    private function readSharedStrings(string $path): array
    {
        $reader = $this->openXlsxXml($path, 'xl/sharedStrings.xml');
        if ($reader === null) {
            return [];
        }

        try {
            $out = [];
            $text = '';
            $insideItem = false;
            $insideText = false;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $insideItem = true;
                    $text = '';
                    continue;
                }
                if ($insideItem && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                    $insideText = true;
                    continue;
                }
                if ($insideItem && $insideText && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $text = $this->appendCellText($text, $reader->value);
                    continue;
                }
                if ($insideItem && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 't') {
                    $insideText = false;
                    continue;
                }
                if ($insideItem && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                    $out[] = $text;
                    if (count($out) > self::MAX_SHARED_STRINGS) {
                        throw new RuntimeException('El archivo Excel supera el limite de textos compartidos permitido.');
                    }
                    $insideItem = false;
                }
            }
            return $out;
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<int,string> $sharedStrings
     * @return array{0: array<int,string>, 1: array<int,array<string,string>>}
     */
    private function readWorksheet(string $path, array $sharedStrings): array
    {
        $reader = $this->openXlsxXml($path, 'xl/worksheets/sheet1.xml');
        if ($reader === null) {
            throw new RuntimeException('El archivo no contiene la hoja Inventario esperada.');
        }

        try {
            $headers = [];
            $rows = [];
            $fallbackRow = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $declaredRow = (int)($reader->getAttribute('r') ?: 0);
                $rowNumber = $declaredRow > 0 ? $declaredRow : ($fallbackRow + 1);
                $fallbackRow = max($fallbackRow, $rowNumber);
                if ($rowNumber <= 0) {
                    continue;
                }
                if ($rowNumber > self::MAX_ROWS + 1) {
                    throw new RuntimeException('El archivo supera el maximo de ' . self::MAX_ROWS . ' filas.');
                }
                $line = $this->readXmlRow($reader, $sharedStrings);
                if ($rowNumber === 1) {
                    foreach ($line as $column => $header) {
                        $headers[$column] = $this->normalizeHeader($header);
                    }
                    continue;
                }
                if ($line === []) {
                    continue;
                }
                $row = [];
                foreach ($headers as $column => $header) {
                    if ($header !== '') {
                        $row[$header] = trim((string)($line[$column] ?? ''));
                    }
                }
                $rows[$rowNumber] = $row;
            }
            if ($headers === []) {
                throw new RuntimeException('La primera fila debe contener los encabezados.');
            }
            return [array_values(array_filter($headers)), $rows];
        } finally {
            $reader->close();
        }
    }

    /** @param array<int,string> $sharedStrings @return array<int,string> */
    private function readXmlRow(XMLReader $reader, array $sharedStrings): array
    {
        $line = [];
        $depth = $reader->depth;
        if ($reader->isEmptyElement) {
            return $line;
        }
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'row') {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }
            $column = $this->columnIndexFromRef((string)$reader->getAttribute('r'));
            if ($column < 0) {
                $this->readXmlCell($reader, $sharedStrings);
                continue;
            }
            if ($column >= self::MAX_COLUMNS) {
                throw new RuntimeException('El archivo Excel supera el limite de columnas permitido.');
            }
            $line[$column] = $this->readXmlCell($reader, $sharedStrings);
        }
        ksort($line);
        return $line;
    }

    /** @param array<int,string> $sharedStrings */
    private function readXmlCell(XMLReader $reader, array $sharedStrings): string
    {
        $type = (string)$reader->getAttribute('t');
        $depth = $reader->depth;
        if ($reader->isEmptyElement) {
            return '';
        }
        $value = '';
        $insideValue = false;
        $insideInlineText = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'c') {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
                $insideValue = true;
                continue;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $type === 'inlineStr') {
                $insideInlineText = true;
                continue;
            }
            if (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true) && ($insideValue || $insideInlineText)) {
                $value = $this->appendCellText($value, $reader->value);
                continue;
            }
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'v') {
                $insideValue = false;
                continue;
            }
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 't') {
                $insideInlineText = false;
            }
        }
        if ($type === 's') {
            return (string)($sharedStrings[(int)$value] ?? '');
        }
        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }
        return $value;
    }

    private function appendCellText(string $current, string $part): string
    {
        $combined = $current . $part;
        if (strlen($combined) > self::MAX_CELL_TEXT_BYTES) {
            throw new RuntimeException('El archivo Excel contiene una celda demasiado extensa.');
        }
        return $combined;
    }

    private function assertSafeXlsxArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw new RuntimeException('El archivo Excel tiene una estructura no permitida.');
        }

        $totalSize = 0;
        $hasSheet = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException('No se pudo inspeccionar el archivo Excel.');
            }
            $name = (string)($stat['name'] ?? '');
            $size = (int)($stat['size'] ?? 0);
            $compressed = (int)($stat['comp_size'] ?? 0);
            if ($name === '' || str_contains($name, '..') || $size < 0 || $compressed < 0) {
                throw new RuntimeException('El archivo Excel tiene una estructura no permitida.');
            }
            $totalSize += $size;
            if ($totalSize > self::MAX_ZIP_UNCOMPRESSED_BYTES || $size > self::MAX_SHEET_XML_BYTES) {
                throw new RuntimeException('El contenido descomprimido del Excel supera el limite permitido.');
            }
            if ($size > 0 && $size > (($compressed + 1) * self::MAX_ZIP_COMPRESSION_RATIO)) {
                throw new RuntimeException('El archivo Excel tiene una compresion no permitida.');
            }
            if ($name === 'xl/worksheets/sheet1.xml') {
                $hasSheet = true;
            }
            if ($name === 'xl/sharedStrings.xml' && $size > self::MAX_SHARED_STRINGS_BYTES) {
                throw new RuntimeException('El catalogo de textos del Excel supera el limite permitido.');
            }
        }
        if (!$hasSheet) {
            throw new RuntimeException('El archivo no contiene la hoja Inventario esperada.');
        }
    }

    private function openXlsxXml(string $path, string $entry): ?XMLReader
    {
        $uri = 'zip://' . str_replace('\\', '/', $path) . '#' . $entry;
        $reader = new XMLReader();
        if (!@$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            $reader->close();
            return null;
        }
        return $reader;
    }

    private function columnIndexFromRef(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return -1;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = ($n * 26) + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim(str_replace("\xEF\xBB\xBF", '', $value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function isGuideOrEmptyRow(array $row): bool
    {
        $values = array_filter(array_map(static fn($v) => trim((string)$v), $row), static fn($v) => $v !== '');
        if ($values === []) {
            return true;
        }
        $name = strtoupper(trim((string)($row['nombre'] ?? '')));
        return $name === 'OBLIGATORIO'
            || str_starts_with($name, 'NOMBRE VISIBLE')
            || str_starts_with($name, 'EJEMPLO');
    }

    private function validateNumberField(array $row, string $field, string $label, array &$item, bool $allowZero): void
    {
        $value = $this->parseNumber((string)($row[$field] ?? ''));
        if ($value === null) {
            $item['errors'][] = $label . ' debe ser numerico.';
            return;
        }
        if ($allowZero ? $value < 0 : $value <= 0) {
            $item['errors'][] = $label . ' debe ser mayor a ' . ($allowZero ? 'o igual a 0.' : '0.');
        }
    }

    private function parseNumber(string $value): ?float
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(["\xc2\xa0", ' ', '$', 'COP'], '', strtoupper($raw));
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        $raw = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
        if ($raw === '' || $raw === '-' || !is_numeric($raw)) {
            return null;
        }
        return (float)$raw;
    }

    private function calculateMarginForPreview(array $row): ?float
    {
        $costo = $this->parseNumber((string)($row['costo_unitario'] ?? ''));
        $precio = $this->parseNumber((string)($row['precio'] ?? ''));
        if ($costo === null || $costo <= 0 || $precio === null || $precio <= 0) {
            return null;
        }

        return round((($precio - $costo) / $costo) * 100, 6);
    }

    private function formatDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private function validatePercentageStorageRange(float $value, string $label, array &$item, ?float $storageMax): void
    {
        if ($storageMax !== null && abs(round($value, 2)) > $storageMax) {
            $item['errors'][] = $label . ' supera el maximo soportado por la base de datos: ' . $this->formatDecimal($storageMax) . '.';
        }
    }

    private function numericColumnMax(PDO $pdo, string $table, string $column): ?float
    {
        $st = $pdo->prepare("
            SELECT numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_schema = 'pos_saas'
              AND table_name = :t
              AND column_name = :c
              AND data_type = 'numeric'
            LIMIT 1
        ");
        $st->execute([':t' => $table, ':c' => $column]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['numeric_precision'] === null || $row['numeric_scale'] === null) {
            return null;
        }

        $integerDigits = (int)$row['numeric_precision'] - (int)$row['numeric_scale'];
        if ($integerDigits <= 0) {
            return null;
        }

        return (10 ** $integerDigits) - (10 ** (-(int)$row['numeric_scale']));
    }

    private function isIntegerNumber(string $value): bool
    {
        $number = $this->parseNumber($value);
        return $number !== null && floor($number) === $number;
    }

    private function hasMaxDecimals(string $value, int $max): bool
    {
        $raw = trim($value);
        if ($raw === '') {
            return true;
        }
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        if (!str_contains($raw, '.')) {
            return true;
        }
        $decimals = preg_replace('/[^0-9]/', '', substr($raw, strpos($raw, '.') + 1)) ?? '';
        return strlen(rtrim($decimals, '0')) <= $max;
    }

    private function normalizeTipoCantidad(string $value): string
    {
        $v = strtoupper(trim($value));
        if ($v === '' || in_array($v, ['UNIDAD', 'UND', 'UNI'], true)) {
            return 'UNIDAD';
        }
        if (in_array($v, ['PESO', 'KG', 'KILOGRAMO', 'KILOGRAMOS'], true)) {
            return 'PESO';
        }
        return $v;
    }

    private function normalizeYesNo(string $value): ?bool
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return false;
        }
        if (in_array($v, ['SI', 'S', 'YES', 'Y', 'TRUE', '1'], true)) {
            return true;
        }
        if (in_array($v, ['NO', 'N', 'FALSE', '0'], true)) {
            return false;
        }
        return null;
    }

    private function normalizeDate(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (is_numeric($v)) {
            $days = (int)$v;
            if ($days > 20000) {
                return gmdate('Y-m-d', ($days - 25569) * 86400);
            }
        }
        return null;
    }

    private function looksScientific(string $value): bool
    {
        return (bool)preg_match('/^\d+(?:[.,]\d+)?E\+\d+$/i', trim($value));
    }

    /**
     * @return array<int,array{nombre:string,unidades_base:?float,precio:?float,sku:string,codigo_barras:string}>
     */
    private function extractPresentations(array $row): array
    {
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $prefix = 'presentacion_' . $i . '_';
            $nombre = trim((string)($row[$prefix . 'nombre'] ?? ''));
            $unidadesRaw = trim((string)($row[$prefix . 'unidades_base'] ?? ''));
            $precioRaw = trim((string)($row[$prefix . 'precio'] ?? ''));
            $sku = trim((string)($row[$prefix . 'sku'] ?? ''));
            $codigo = trim((string)($row[$prefix . 'codigo_barras'] ?? ''));
            if ($nombre === '' && $unidadesRaw === '' && $precioRaw === '' && $sku === '' && $codigo === '') {
                continue;
            }
            $out[$i] = [
                'nombre' => $nombre,
                'unidades_base' => $unidadesRaw !== '' ? $this->parseNumber($unidadesRaw) : null,
                'precio' => $precioRaw !== '' ? $this->parseNumber($precioRaw) : null,
                'sku' => $sku,
                'codigo_barras' => $codigo,
            ];
        }
        return $out;
    }

    private function markFileDuplicates(array &$items, array $map, string $field, string $message): void
    {
        foreach ($map as $rows) {
            if (count($rows) <= 1) {
                continue;
            }
            foreach ($rows as $row) {
                if (isset($items[$row])) {
                    $items[$row]['errors'][] = $message . ' Campo: ' . $field . '.';
                }
            }
        }
    }

    private function markTenantDuplicates(PDO $tenant, int $empresaId, array &$items, array $values, string $field, string $message): void
    {
        $values = array_values(array_filter(array_unique($values), static fn($v) => trim((string)$v) !== ''));
        if ($values === []) {
            return;
        }
        $column = $field === 'sku_producto' ? 'sku_producto' : 'codigo_barras_producto';
        $normalized = $field === 'sku_producto';
        $existing = $this->existingValues($tenant, 'pos_saas.producto', $column, $empresaId, $values, $normalized);
        if ($existing === []) {
            return;
        }
        foreach ($items as &$item) {
            $candidate = $field === 'sku_producto' ? $this->normalizeKey((string)$item['sku']) : (string)$item['codigo_barras'];
            if ($candidate !== '' && isset($existing[$candidate])) {
                $item['errors'][] = $message;
            }
        }
        unset($item);
    }

    private function markPresentationTenantDuplicates(PDO $tenant, int $empresaId, array &$items, array $valueRows, string $field, string $message): void
    {
        if (!$this->tableExists($tenant, 'producto_presentacion')) {
            return;
        }
        $values = array_keys($valueRows);
        $values = array_values(array_filter(array_unique($values), static fn($v) => trim((string)$v) !== ''));
        if ($values === []) {
            return;
        }
        $normalized = $field === 'sku_presentacion';
        $existing = $this->existingValues($tenant, 'pos_saas.producto_presentacion', $field, $empresaId, $values, $normalized);
        if ($existing === []) {
            return;
        }
        foreach (array_keys($existing) as $value) {
            foreach (($valueRows[$value] ?? []) as $row) {
                if (isset($items[$row])) {
                    $items[$row]['errors'][] = $message;
                }
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private function existingValues(PDO $pdo, string $table, string $column, int $empresaId, array $values, bool $lower): array
    {
        $placeholders = [];
        $params = [':e' => $empresaId];
        foreach ($values as $i => $value) {
            $key = ':v' . $i;
            $placeholders[] = $key;
            $params[$key] = $lower ? $this->normalizeKey((string)$value) : (string)$value;
        }
        $expr = $lower ? 'LOWER(btrim(' . $column . '))' : 'btrim(' . $column . ')';
        $sql = 'SELECT ' . $expr . ' AS value FROM ' . $table . ' WHERE id_empresa = :e AND ' . $column . ' IS NOT NULL AND ' . $expr . ' IN (' . implode(',', $placeholders) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
            $out[(string)$value] = true;
        }
        return $out;
    }

    private function tableExists(PDO $pdo, string $tableName): bool
    {
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'pos_saas'
              AND table_name = :t
            LIMIT 1
        ");
        $st->execute([':t' => $tableName]);
        return (bool)$st->fetchColumn();
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim($value));
    }

    private function capabilityEnabled(array $config, string $code): bool
    {
        return BusinessConfigService::toBool(($config['capacidades'] ?? [])[$code] ?? false);
    }

    private function tenantConnection(PDO $control, int $empresaId): ?PDO
    {
        $tenant = BusinessConfigService::getTenantMapping($control, $empresaId);
        if (!$tenant || trim((string)($tenant['db_name'] ?? '')) === '') {
            return null;
        }

        try {
            return Database::connectTenant((string)$tenant['db_name'], $tenant);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DB_TENANT_CREDENTIALS_REQUIRED') {
                throw $e;
            }
            throw new RuntimeException('No se pudo conectar al tenant para validar duplicados.');
        }
    }
}

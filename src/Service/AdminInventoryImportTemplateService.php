<?php

declare(strict_types=1);

namespace PosAdmin\Service;

final class AdminInventoryImportTemplateService
{
    /**
     * @param array<string, mixed> $config
     * @return array{filename:string, content:string, mime:string}
     */
    public function buildXlsx(array $config): array
    {
        $companyId = (int)($config['id_empresa'] ?? 0);
        $businessType = strtoupper(trim((string)($config['tipo_negocio'] ?? 'GENERAL')));
        $capabilities = is_array($config['capacidades'] ?? null) ? $config['capacidades'] : [];

        $hasWeight = !empty($capabilities[BusinessConfigService::CAP_PRODUCTOS_PESO]);
        $hasPresentations = !empty($capabilities[BusinessConfigService::CAP_PRODUCTOS_PRESENTACION]);
        $hasLots = !empty($capabilities[BusinessConfigService::CAP_LOTES_VENCIMIENTOS]);

        $columns = $this->columns($hasWeight, $hasPresentations, $hasLots);
        $example = $this->exampleRow($businessType, $hasWeight, $hasPresentations, $hasLots);

        $inventoryRows = [
            array_map(static fn(array $col): string => $col['header'], $columns),
            array_map(static fn(array $col): string => (string)($example[$col['key']] ?? ''), $columns),
        ];

        $guideRows = $this->guideRows($columns, $example, $businessType, $hasWeight, $hasPresentations, $hasLots);
        $content = $this->toXlsx($inventoryRows, $guideRows, $columns);
        $suffix = $companyId > 0 ? 'empresa_' . $companyId : 'empresa';

        return [
            'filename' => 'plantilla_inventario_inicial_' . $suffix . '.xlsx',
            'content' => $content,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * @return list<array{key:string,header:string,required:bool,help:string}>
     */
    private function columns(bool $hasWeight, bool $hasPresentations, bool $hasLots): array
    {
        $columns = [
            ['key' => 'nombre', 'header' => 'nombre', 'required' => true, 'help' => 'Nombre visible del producto. No borres las 3 primeras filas de guia'],
            ['key' => 'costo_unitario', 'header' => 'costo_unitario', 'required' => true, 'help' => 'Costo por unidad o por KG'],
            ['key' => 'utilidad_porcentaje', 'header' => 'utilidad_porcentaje', 'required' => true, 'help' => 'Margen de utilidad. Ej: 40'],
            ['key' => 'precio', 'header' => 'precio', 'required' => true, 'help' => 'Precio final de venta'],
            ['key' => 'stock', 'header' => 'stock', 'required' => true, 'help' => 'Cantidad inicial. Entero o decimal si es peso'],
            ['key' => 'sku', 'header' => 'sku', 'required' => false, 'help' => 'Codigo interno unico'],
            ['key' => 'codigo_barras', 'header' => 'codigo_barras', 'required' => false, 'help' => 'Codigo de barras unico'],
            ['key' => 'marca', 'header' => 'marca', 'required' => false, 'help' => 'Marca del producto'],
            ['key' => 'color', 'header' => 'color', 'required' => false, 'help' => 'Color si aplica'],
            ['key' => 'talla', 'header' => 'talla', 'required' => false, 'help' => 'Talla o referencia si aplica'],
            ['key' => 'descripcion', 'header' => 'descripcion', 'required' => false, 'help' => 'Descripcion amplia del producto'],
            ['key' => 'categoria', 'header' => 'categoria', 'required' => false, 'help' => 'Se buscara o creara si no existe'],
            ['key' => 'proveedor', 'header' => 'proveedor', 'required' => false, 'help' => 'Se buscara o creara si no existe'],
            ['key' => 'unidad_medida', 'header' => 'unidad_medida', 'required' => false, 'help' => 'Ej: Unidad, Kilogramo, Gramos'],
            ['key' => 'simbolo_unidad', 'header' => 'simbolo_unidad', 'required' => false, 'help' => 'Ej: UND, KG, G'],
            ['key' => 'impuesto_nombre', 'header' => 'impuesto_nombre', 'required' => false, 'help' => 'Ej: IVA 19'],
            ['key' => 'impuesto_tasa', 'header' => 'impuesto_tasa', 'required' => false, 'help' => 'Porcentaje. Ej: 19'],
        ];

        if ($hasWeight) {
            $columns[] = ['key' => 'tipo_cantidad', 'header' => 'tipo_cantidad', 'required' => false, 'help' => 'UNIDAD o PESO'];
        }

        if ($hasLots) {
            $columns[] = ['key' => 'lote', 'header' => 'lote', 'required' => false, 'help' => 'Codigo del lote si aplica'];
            $columns[] = ['key' => 'fecha_vencimiento', 'header' => 'fecha_vencimiento', 'required' => false, 'help' => 'Formato YYYY-MM-DD'];
        }

        if ($hasPresentations) {
            $columns[] = ['key' => 'usa_presentaciones', 'header' => 'usa_presentaciones', 'required' => false, 'help' => 'SI o NO'];
            $columns[] = ['key' => 'presentacion_1_nombre', 'header' => 'presentacion_1_nombre', 'required' => false, 'help' => 'Ej: Blister'];
            $columns[] = ['key' => 'presentacion_1_unidades_base', 'header' => 'presentacion_1_unidades_base', 'required' => false, 'help' => 'Ej: 10'];
            $columns[] = ['key' => 'presentacion_1_precio', 'header' => 'presentacion_1_precio', 'required' => false, 'help' => 'Precio de esa presentacion'];
            $columns[] = ['key' => 'presentacion_1_sku', 'header' => 'presentacion_1_sku', 'required' => false, 'help' => 'SKU opcional de presentacion'];
            $columns[] = ['key' => 'presentacion_1_codigo_barras', 'header' => 'presentacion_1_codigo_barras', 'required' => false, 'help' => 'Codigo opcional de presentacion'];
            $columns[] = ['key' => 'presentacion_2_nombre', 'header' => 'presentacion_2_nombre', 'required' => false, 'help' => 'Ej: Caja'];
            $columns[] = ['key' => 'presentacion_2_unidades_base', 'header' => 'presentacion_2_unidades_base', 'required' => false, 'help' => 'Ej: 30'];
            $columns[] = ['key' => 'presentacion_2_precio', 'header' => 'presentacion_2_precio', 'required' => false, 'help' => 'Precio de esa presentacion'];
            $columns[] = ['key' => 'presentacion_2_sku', 'header' => 'presentacion_2_sku', 'required' => false, 'help' => 'SKU opcional de presentacion'];
            $columns[] = ['key' => 'presentacion_2_codigo_barras', 'header' => 'presentacion_2_codigo_barras', 'required' => false, 'help' => 'Codigo opcional de presentacion'];
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    private function exampleRow(string $businessType, bool $hasWeight, bool $hasPresentations, bool $hasLots): array
    {
        $row = [
            'nombre' => 'EJEMPLO - borrar esta fila antes de importar',
            'costo_unitario' => '2500',
            'utilidad_porcentaje' => '40',
            'precio' => '3500',
            'stock' => '10',
            'sku' => 'SKU-EJEMPLO',
            'codigo_barras' => '7700000000000',
            'marca' => 'Marca ejemplo',
            'color' => '',
            'talla' => '',
            'descripcion' => 'Descripcion opcional del producto',
            'categoria' => 'General',
            'proveedor' => 'Proveedor ejemplo',
            'unidad_medida' => 'Unidad',
            'simbolo_unidad' => 'UND',
            'impuesto_nombre' => 'Sin impuesto 0%',
            'impuesto_tasa' => '0',
        ];

        if ($hasWeight || $businessType === 'TIENDA_MINIMARKET') {
            $row['tipo_cantidad'] = 'UNIDAD';
        }

        if ($hasLots) {
            $row['lote'] = 'LOTE-001';
            $row['fecha_vencimiento'] = '2027-12-31';
        }

        if ($hasPresentations) {
            $row['usa_presentaciones'] = 'SI';
            $row['presentacion_1_nombre'] = 'Blister';
            $row['presentacion_1_unidades_base'] = '10';
            $row['presentacion_1_precio'] = '28000';
            $row['presentacion_1_sku'] = 'SKU-EJEMPLO-BL10';
            $row['presentacion_1_codigo_barras'] = '';
            $row['presentacion_2_nombre'] = 'Caja';
            $row['presentacion_2_unidades_base'] = '30';
            $row['presentacion_2_precio'] = '84000';
            $row['presentacion_2_sku'] = 'SKU-EJEMPLO-CA30';
            $row['presentacion_2_codigo_barras'] = '';
        }

        return $row;
    }

    /**
     * @param list<array{key:string,header:string,required:bool,help:string}> $columns
     * @param array<string, string> $example
     * @return list<list<string>>
     */
    private function guideRows(array $columns, array $example, string $businessType, bool $hasWeight, bool $hasPresentations, bool $hasLots): array
    {
        $rows = [
            ['Plantilla de inventario inicial', 'Lee esta hoja antes de llenar la hoja Inventario'],
            ['', ''],
            ['Reglas generales', ''],
            ['1', 'Llena un producto por fila en la hoja Inventario.'],
            ['2', 'No cambies los nombres de las columnas.'],
            ['3', 'Los campos obligatorios son: nombre, costo_unitario, utilidad_porcentaje, precio y stock.'],
            ['4', 'Los valores monetarios van sin signo pesos y sin separador de miles. Ej: 2500 o 2500.50.'],
            ['5', 'SKU y codigo_barras son opcionales, pero si los usas no deben repetirse.'],
            ['6', 'La fila de ejemplo se debe borrar antes de importar.'],
            ['', ''],
            ['Tipo de negocio detectado', $businessType],
            ['Maneja productos por peso', $hasWeight ? 'SI' : 'NO'],
            ['Maneja lotes/vencimientos', $hasLots ? 'SI' : 'NO'],
            ['Maneja presentaciones', $hasPresentations ? 'SI' : 'NO'],
            ['', ''],
            ['Campo', 'Obligatorio', 'Descripcion', 'Ejemplo'],
        ];

        foreach ($columns as $col) {
            $rows[] = [
                $col['header'],
                $col['required'] ? 'SI' : 'NO',
                $col['help'],
                (string)($example[$col['key']] ?? ''),
            ];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['Ejemplos por caso', '', '', ''];
        $rows[] = ['Producto normal', 'stock entero', 'tipo_cantidad vacio o UNIDAD', 'Arroz 500g, stock 25'];
        $rows[] = ['Producto por peso', 'stock decimal permitido', 'tipo_cantidad PESO', 'Papa parda, stock 25.500'];
        $rows[] = ['Producto con lote', 'lote y fecha opcionales', 'fecha_vencimiento YYYY-MM-DD', 'LOTE-001, 2027-12-31'];
        $rows[] = ['Producto con presentaciones', 'usa_presentaciones SI', 'agrega blister/caja como columnas extra', 'Blister x 10, Caja x 30'];

        return $rows;
    }

    /**
     * @param list<list<string>> $inventoryRows
     * @param list<list<string>> $guideRows
     * @param list<array{key:string,header:string,required:bool,help:string}> $columns
     */
    private function toXlsx(array $inventoryRows, array $guideRows, array $columns): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('La extension ZipArchive de PHP es requerida para generar XLSX');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bersano_xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear archivo temporal para la plantilla');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo generar la plantilla XLSX');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->appXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($inventoryRows, $this->inventoryWidths($columns), true));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->worksheetXml($guideRows, [28, 18, 70, 32], false));
        $zip->close();

        $content = file_get_contents($tmp);
        @unlink($tmp);

        if ($content === false) {
            throw new \RuntimeException('No se pudo leer la plantilla generada');
        }

        return $content;
    }

    /**
     * @param list<array{key:string,header:string,required:bool,help:string}> $columns
     * @return list<int>
     */
    private function inventoryWidths(array $columns): array
    {
        $widths = [];
        foreach ($columns as $col) {
            $header = $col['header'];
            $widths[] = match ($header) {
                'nombre', 'descripcion' => 34,
                'codigo_barras', 'fecha_vencimiento' => 20,
                'utilidad_porcentaje' => 22,
                default => 18,
            };
        }

        return $widths;
    }

    /**
     * @param list<list<string>> $rows
     * @param list<int> $widths
     */
    private function worksheetXml(array $rows, array $widths, bool $inventory): string
    {
        $cols = '';
        foreach ($widths as $index => $width) {
            $col = $index + 1;
            $cols .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
        }

        $sheetData = '';
        foreach ($rows as $rowIndex => $row) {
            $r = $rowIndex + 1;
            $sheetData .= '<row r="' . $r . '">';
            foreach ($row as $colIndex => $value) {
                $style = $this->cellStyle($inventory, $r, $colIndex);
                $sheetData .= $this->cellXml($colIndex + 1, $r, $value, $style);
            }
            $sheetData .= '</row>';
        }

        $lastCol = $this->columnName(max(1, count($rows[0] ?? [])));
        $lastRow = max(1, count($rows));
        $dimension = 'A1:' . $lastCol . $lastRow;
        $filter = $inventory ? '<autoFilter ref="A1:' . $lastCol . '1"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dimension . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $sheetData . '</sheetData>'
            . $filter
            . '</worksheet>';
    }

    private function cellStyle(bool $inventory, int $row, int $colIndex): int
    {
        if ($row === 1) {
            return 1;
        }

        if (!$inventory && $row === 16) {
            return 2;
        }

        if (!$inventory && ($row === 1 || $row === 3 || $row === 36)) {
            return 4;
        }

        return 3;
    }

    private function cellXml(int $col, int $row, string $value, int $style): string
    {
        $ref = $this->columnName($col) . $row;
        $text = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . $text . '</t></is></c>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Inventario" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Guia" sheetId="2" r:id="rId2"/>'
            . '</sheets>'
            . '</workbook>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function appXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Bersano POS</Application>'
            . '</Properties>';
    }

    private function coreXml(): string
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Bersano POS</dc:creator>'
            . '<cp:lastModifiedBy>Bersano POS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }
}

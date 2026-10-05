<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;
use PosAdmin\Core\Database;
use RuntimeException;
use Throwable;

final class AdminInventoryImportConfirmService
{
    private ?bool $hasPresentationTable = null;
    /** @var array<string, ?float> */
    private array $numericColumnMaxCache = [];
    /** @var array<string, ?int> */
    private array $categoryCache = [];
    /** @var array<string, ?int> */
    private array $providerCache = [];
    /** @var array<string, ?int> */
    private array $unitCache = [];
    /** @var array<string, ?int> */
    private array $taxCache = [];

    public function confirm(PDO $control, int $empresaId, array $upload): array
    {
        $this->prepareLongImportRuntime();
        $this->resetCaches();

        $previewService = new AdminInventoryImportPreviewService();
        $preview = $previewService->preview($control, $empresaId, $upload);

        if (!($preview['ok'] ?? false) || !($preview['summary']['can_confirm'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'IMPORT_NOT_READY',
                'message' => 'Corrige el archivo antes de confirmar la importacion.',
                'preview' => $preview,
            ];
        }

        [, $rows] = $previewService->readRowsForImport($upload);
        if ($rows === []) {
            return [
                'ok' => false,
                'error' => 'EMPTY_IMPORT',
                'message' => 'El archivo no tiene productos para importar.',
                'preview' => $preview,
            ];
        }

        $config = BusinessConfigService::getConfig($control, $empresaId);
        $allowLots = BusinessConfigService::toBool(
            ($config['capacidades'] ?? [])[BusinessConfigService::CAP_LOTES_VENCIMIENTOS] ?? false
        );

        $tenant = $this->tenantConnection($control, $empresaId);

        $summary = [
            'registros_creados' => 0,
            'productos_creados' => 0,
            'inventarios_creados' => 0,
            'lotes_creados' => 0,
            'presentaciones_creadas' => 0,
            'movimientos_creados' => 0,
        ];

        $tenant->beginTransaction();
        try {
            foreach ($rows as $rowNumber => $row) {
                $this->importRow($tenant, $empresaId, (int)$rowNumber, $row, $summary, $allowLots);
            }

            $tenant->commit();

            return [
                'ok' => true,
                'message' => 'Inventario inicial importado correctamente.',
                'id_empresa' => $empresaId,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            if ($tenant->inTransaction()) {
                $tenant->rollBack();
            }

            throw new RuntimeException('No se pudo confirmar la importacion: ' . $this->friendlyDbError($e), 0, $e);
        }
    }

    private function importRow(PDO $pdo, int $empresaId, int $rowNumber, array $row, array &$summary, bool $allowLots): void
    {
        $nombre = $this->clean((string)($row['nombre'] ?? ''));
        $costo = $this->number((string)($row['costo_unitario'] ?? ''), 'Costo unitario', $rowNumber);
        $precio = $this->number((string)($row['precio'] ?? ''), 'Precio', $rowNumber);
        $utilidadRaw = $this->clean((string)($row['utilidad_porcentaje'] ?? ''));
        $utilidad = $utilidadRaw !== ''
            ? $this->number($utilidadRaw, 'Utilidad', $rowNumber)
            : $this->calculateMargin($costo, $precio, $rowNumber);
        $this->assertPositive($utilidad, 'Utilidad', $rowNumber);
        $this->assertPercentageStorageRange($pdo, 'producto', 'margen_porcentaje', $utilidad, 'Utilidad', $rowNumber);
        $stock = $this->number((string)($row['stock'] ?? ''), 'Stock', $rowNumber);
        $tipoCantidad = $this->normalizeTipoCantidad((string)($row['tipo_cantidad'] ?? ''));

        if ($nombre === '') {
            throw new RuntimeException('Fila ' . $rowNumber . ': nombre es obligatorio.');
        }
        if ($stock <= 0) {
            throw new RuntimeException('Fila ' . $rowNumber . ': stock debe ser mayor a 0.');
        }

        $sku = $this->nullable((string)($row['sku'] ?? ''));
        $barcode = $this->nullable((string)($row['codigo_barras'] ?? ''));

        $idCategoria = $this->findOrCreateCategory($pdo, $empresaId, (string)($row['categoria'] ?? ''));
        $idProveedor = $this->findOrCreateProvider($pdo, $empresaId, (string)($row['proveedor'] ?? ''));
        $idUnidad = $this->findOrCreateUnit($pdo, $empresaId, (string)($row['unidad_medida'] ?? ''), (string)($row['simbolo_unidad'] ?? ''));
        $idImpuesto = $this->findOrCreateTax($pdo, $empresaId, (string)($row['impuesto_nombre'] ?? ''), (string)($row['impuesto_tasa'] ?? ''), $rowNumber);

        $lote = $this->nullable((string)($row['lote'] ?? ''));
        $fechaVencimiento = $this->normalizeDate((string)($row['fecha_vencimiento'] ?? ''));
        if (!$allowLots) {
            $lote = null;
            $fechaVencimiento = null;
        }
        $usesLot = $lote !== null || $fechaVencimiento !== null;

        $idProducto = $this->createProduct($pdo, [
            'id_empresa' => $empresaId,
            'nombre' => $nombre,
            'descripcion' => $this->nullable((string)($row['descripcion'] ?? '')),
            'precio' => $precio,
            'stock' => $stock,
            'id_categoria' => $idCategoria,
            'sku_producto' => $sku,
            'codigo_barras_producto' => $barcode,
            'id_impuesto' => $idImpuesto,
            'id_unidad_medida' => $idUnidad,
            'costo_unitario' => $costo,
            'margen_porcentaje' => $utilidad,
            'id_proveedor_principal' => $idProveedor,
            'fecha_vencimiento' => $fechaVencimiento,
            'usa_lote' => $usesLot,
            'requiere_vencimiento' => $fechaVencimiento !== null,
            'marca' => $this->nullable((string)($row['marca'] ?? '')),
            'talla' => $this->nullable((string)($row['talla'] ?? '')),
            'color' => $this->nullable((string)($row['color'] ?? '')),
            'tipo_cantidad' => $tipoCantidad,
        ]);
        $summary['productos_creados']++;

        $this->createInventory($pdo, $empresaId, $idProducto, $stock);
        $summary['inventarios_creados']++;

        $idLote = null;
        if ($usesLot) {
            $idLote = $this->createLot($pdo, $empresaId, $idProducto, $lote, $fechaVencimiento, $stock, $costo, $rowNumber);
            $summary['lotes_creados']++;
        }

        $idInicial = $this->createInitialHeader($pdo, $empresaId);
        $summary['registros_creados']++;

        $this->createInitialDetail($pdo, $empresaId, $idInicial, $idProducto, $stock, $costo, $lote, $fechaVencimiento);
        $this->createMovement($pdo, $empresaId, $idProducto, $stock, $costo, $idInicial, $idLote, $idProveedor, $rowNumber);
        $summary['movimientos_creados']++;

        if ($this->yesNo((string)($row['usa_presentaciones'] ?? '')) === true) {
            if (!$this->hasPresentationTable($pdo)) {
                throw new RuntimeException('Fila ' . $rowNumber . ': el tenant no tiene la tabla producto_presentacion.');
            }
            $summary['presentaciones_creadas'] += $this->createPresentations($pdo, $empresaId, $idProducto, $row, $precio, $sku, $barcode, $rowNumber);
        }
    }

    private function createInitialHeader(PDO $pdo, int $empresaId): int
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.inventario_inicial (
                id_empresa, estado, modo, actualizar_costo_producto,
                id_usuario_creador, id_usuario_confirmador, confirmado_at,
                created_at, updated_at
            )
            VALUES (:e, 'CONFIRMADO', 'set', true, NULL, NULL, now(), now(), now())
            RETURNING id_inicial
        ");
        $st->execute([':e' => $empresaId]);
        return (int)$st->fetchColumn();
    }

    private function createProduct(PDO $pdo, array $data): int
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.producto (
                id_empresa, nombre, descripcion, precio, stock, estado,
                id_categoria, sku_producto, codigo_barras_producto, id_impuesto,
                id_unidad_medida, costo_unitario, margen_porcentaje,
                id_proveedor_principal, fecha_vencimiento, dias_alerta,
                usa_lote, requiere_vencimiento, marca, talla, color,
                es_inventario_inicial, tipo_cantidad, created_at, updated_at
            )
            VALUES (
                :id_empresa, :nombre, :descripcion, :precio, :stock, 1,
                :id_categoria, :sku_producto, :codigo_barras_producto, :id_impuesto,
                :id_unidad_medida, :costo_unitario, :margen_porcentaje,
                :id_proveedor_principal, :fecha_vencimiento, 10,
                :usa_lote, :requiere_vencimiento, :marca, :talla, :color,
                true, :tipo_cantidad, now(), now()
            )
            RETURNING id_producto
        ");
        $st->execute([
            ':id_empresa' => $data['id_empresa'],
            ':nombre' => $data['nombre'],
            ':descripcion' => $data['descripcion'],
            ':precio' => $data['precio'],
            ':stock' => $data['stock'],
            ':id_categoria' => $data['id_categoria'],
            ':sku_producto' => $data['sku_producto'],
            ':codigo_barras_producto' => $data['codigo_barras_producto'],
            ':id_impuesto' => $data['id_impuesto'],
            ':id_unidad_medida' => $data['id_unidad_medida'],
            ':costo_unitario' => $data['costo_unitario'],
            ':margen_porcentaje' => $data['margen_porcentaje'],
            ':id_proveedor_principal' => $data['id_proveedor_principal'],
            ':fecha_vencimiento' => $data['fecha_vencimiento'],
            ':usa_lote' => $data['usa_lote'] ? 1 : 0,
            ':requiere_vencimiento' => $data['requiere_vencimiento'] ? 1 : 0,
            ':marca' => $data['marca'],
            ':talla' => $data['talla'],
            ':color' => $data['color'],
            ':tipo_cantidad' => $data['tipo_cantidad'],
        ]);

        return (int)$st->fetchColumn();
    }

    private function createInventory(PDO $pdo, int $empresaId, int $productoId, float $stock): void
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.inventario (
                id_empresa, id_producto, stock_inicial, stock_actual, created_at, updated_at
            )
            VALUES (:e, :p, :q, :q, now(), now())
            ON CONFLICT (id_empresa, id_producto)
            DO UPDATE SET
                stock_inicial = EXCLUDED.stock_inicial,
                stock_actual = EXCLUDED.stock_actual,
                updated_at = now()
        ");
        $st->execute([':e' => $empresaId, ':p' => $productoId, ':q' => $stock]);
    }

    private function createInitialDetail(PDO $pdo, int $empresaId, int $idInicial, int $productoId, float $cantidad, float $costo, ?string $lote, ?string $fechaVencimiento): void
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.inventario_inicial_detalle (
                id_empresa, id_inicial, id_producto, cantidad, costo_unitario,
                lote, fecha_vencimiento, created_at, updated_at
            )
            VALUES (:e, :i, :p, :q, :c, :l, :fv, now(), now())
        ");
        $st->execute([
            ':e' => $empresaId,
            ':i' => $idInicial,
            ':p' => $productoId,
            ':q' => $cantidad,
            ':c' => $costo,
            ':l' => $lote,
            ':fv' => $fechaVencimiento,
        ]);
    }

    private function createLot(PDO $pdo, int $empresaId, int $productoId, ?string $lote, ?string $fechaVencimiento, float $stock, float $costo, int $rowNumber): int
    {
        $loteFinal = $lote ?: ('INI-' . $empresaId . '-' . $productoId . '-' . $rowNumber);
        $loteFinal = substr($loteFinal, 0, 50);

        $st = $pdo->prepare("
            INSERT INTO pos_saas.producto_lote (
                id_empresa, id_producto, lote, fecha_vencimiento, stock_lote,
                costo_ultima_compra, costo_promedio_lote, dias_alerta,
                created_at, updated_at
            )
            VALUES (:e, :p, :l, :fv, :q, :c, :c, 10, now(), now())
            RETURNING id_lote
        ");
        $st->execute([
            ':e' => $empresaId,
            ':p' => $productoId,
            ':l' => $loteFinal,
            ':fv' => $fechaVencimiento,
            ':q' => $stock,
            ':c' => $costo,
        ]);

        return (int)$st->fetchColumn();
    }

    private function createMovement(PDO $pdo, int $empresaId, int $productoId, float $cantidad, float $costo, int $idInicial, ?int $idLote, ?int $idProveedor, int $rowNumber): void
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.movimientos_inventario (
                id_empresa, id_producto, tipo, motivo, cantidad, costo_unitario,
                referencia_tipo, referencia_id, observaciones, id_lote, id_proveedor,
                created_at
            )
            VALUES (
                :e, :p, 'IN', 'alta_inicial', :q, :c,
                'inventario.inicial.registro', :ref,
                :obs, :lote, :prov, now()
            )
        ");
        $st->execute([
            ':e' => $empresaId,
            ':p' => $productoId,
            ':q' => $cantidad,
            ':c' => $costo,
            ':ref' => $idInicial,
            ':obs' => 'Importacion inicial desde panel admin. Fila ' . $rowNumber . '.',
            ':lote' => $idLote,
            ':prov' => $idProveedor,
        ]);
    }

    private function createPresentations(PDO $pdo, int $empresaId, int $productoId, array $row, float $basePrice, ?string $productSku, ?string $productBarcode, int $rowNumber): int
    {
        $created = 0;
        $this->insertPresentation($pdo, $empresaId, $productoId, 'Unidad', 1, $basePrice, null, null, true, 0);
        $created++;

        for ($i = 1; $i <= 5; $i++) {
            $prefix = 'presentacion_' . $i . '_';
            $nombre = $this->clean((string)($row[$prefix . 'nombre'] ?? ''));
            $cantidadRaw = $this->clean((string)($row[$prefix . 'unidades_base'] ?? ''));
            $precioRaw = $this->clean((string)($row[$prefix . 'precio'] ?? ''));
            $sku = $this->nullable((string)($row[$prefix . 'sku'] ?? ''));
            $barcode = $this->nullable((string)($row[$prefix . 'codigo_barras'] ?? ''));

            if ($nombre === '' && $cantidadRaw === '' && $precioRaw === '' && $sku === null && $barcode === null) {
                continue;
            }

            $cantidad = (int)$this->number($cantidadRaw, 'Unidades base presentacion ' . $i, $rowNumber);
            if ($cantidad <= 0) {
                throw new RuntimeException('Fila ' . $rowNumber . ': unidades base de presentacion debe ser mayor a 0.');
            }
            $precio = $precioRaw !== ''
                ? $this->number($precioRaw, 'Precio presentacion ' . $i, $rowNumber)
                : ($basePrice * $cantidad);

            if ($sku !== null && $productSku !== null && strtolower($sku) === strtolower($productSku)) {
                throw new RuntimeException('Fila ' . $rowNumber . ': SKU de presentacion no puede repetir el SKU del producto.');
            }
            if ($barcode !== null && $productBarcode !== null && $barcode === $productBarcode) {
                throw new RuntimeException('Fila ' . $rowNumber . ': codigo de barras de presentacion no puede repetir el codigo del producto.');
            }

            $this->insertPresentation($pdo, $empresaId, $productoId, $nombre, $cantidad, $precio, $sku, $barcode, false, $i);
            $created++;
        }

        return $created;
    }

    private function insertPresentation(PDO $pdo, int $empresaId, int $productoId, string $name, int $quantityBase, float $price, ?string $sku, ?string $barcode, bool $default, int $order): void
    {
        $st = $pdo->prepare("
            INSERT INTO pos_saas.producto_presentacion (
                id_empresa, id_producto, nombre, cantidad_base, precio_venta,
                codigo_barras, sku_presentacion, es_default, estado, orden,
                created_at, updated_at
            )
            VALUES (:e, :p, :n, :q, :price, :barcode, :sku, :def, 1, :ord, now(), now())
        ");
        $st->execute([
            ':e' => $empresaId,
            ':p' => $productoId,
            ':n' => $name,
            ':q' => $quantityBase,
            ':price' => $price,
            ':barcode' => $barcode,
            ':sku' => $sku,
            ':def' => $default ? 1 : 0,
            ':ord' => $order,
        ]);
    }

    private function findOrCreateCategory(PDO $pdo, int $empresaId, string $name): ?int
    {
        $name = $this->clean($name);
        if ($name === '') {
            return null;
        }
        $cacheKey = $this->normalizeKey($name);
        if (array_key_exists($cacheKey, $this->categoryCache)) {
            return $this->categoryCache[$cacheKey];
        }
        $existing = $this->findByName($pdo, 'pos_saas.categoria', 'id_categoria', 'nombre', $empresaId, $name);
        if ($existing !== null) {
            $this->categoryCache[$cacheKey] = $existing;
            return $existing;
        }
        $st = $pdo->prepare("
            INSERT INTO pos_saas.categoria (id_empresa, nombre, descripcion, estado, created_at, updated_at)
            VALUES (:e, :n, NULL, 1, now(), now())
            RETURNING id_categoria
        ");
        $st->execute([':e' => $empresaId, ':n' => $name]);
        $created = (int)$st->fetchColumn();
        $this->categoryCache[$cacheKey] = $created;
        return $created;
    }

    private function findOrCreateProvider(PDO $pdo, int $empresaId, string $name): ?int
    {
        $name = $this->clean($name);
        if ($name === '') {
            return null;
        }
        $cacheKey = $this->normalizeKey($name);
        if (array_key_exists($cacheKey, $this->providerCache)) {
            return $this->providerCache[$cacheKey];
        }
        $existing = $this->findByName($pdo, 'pos_saas.proveedor', 'id_proveedor', 'nombre', $empresaId, $name);
        if ($existing !== null) {
            $this->providerCache[$cacheKey] = $existing;
            return $existing;
        }
        $st = $pdo->prepare("
            INSERT INTO pos_saas.proveedor (id_empresa, nombre, estado, created_at, updated_at)
            VALUES (:e, :n, 1, now(), now())
            RETURNING id_proveedor
        ");
        $st->execute([':e' => $empresaId, ':n' => $name]);
        $created = (int)$st->fetchColumn();
        $this->providerCache[$cacheKey] = $created;
        return $created;
    }

    private function findOrCreateUnit(PDO $pdo, int $empresaId, string $name, string $symbol): ?int
    {
        $name = $this->clean($name);
        if ($name === '') {
            return null;
        }
        $cacheKey = $this->normalizeKey($name);
        if (array_key_exists($cacheKey, $this->unitCache)) {
            return $this->unitCache[$cacheKey];
        }
        $existing = $this->findByName($pdo, 'pos_saas.unidad_medida', 'id_unidad_medida', 'nombre_unidad_medida', $empresaId, $name);
        if ($existing !== null) {
            $this->unitCache[$cacheKey] = $existing;
            return $existing;
        }
        $symbol = $this->clean($symbol) ?: $this->defaultUnitSymbol($name);
        $st = $pdo->prepare("
            INSERT INTO pos_saas.unidad_medida (
                id_empresa, nombre_unidad_medida, simbolo_unidad_medida, estado, created_at, updated_at
            )
            VALUES (:e, :n, :s, 1, now(), now())
            RETURNING id_unidad_medida
        ");
        $st->execute([':e' => $empresaId, ':n' => $name, ':s' => substr($symbol, 0, 16)]);
        $created = (int)$st->fetchColumn();
        $this->unitCache[$cacheKey] = $created;
        return $created;
    }

    private function findOrCreateTax(PDO $pdo, int $empresaId, string $name, string $rateRaw, int $rowNumber): ?int
    {
        $name = $this->clean($name);
        $rate = $this->parseNumber($rateRaw);
        if ($name === '' && ($rate === null || $rate <= 0)) {
            return null;
        }
        if ($name === '') {
            $name = 'IVA ' . rtrim(rtrim(number_format((float)$rate, 3, '.', ''), '0'), '.') . '%';
        }
        if ($rate === null) {
            $rate = 0.0;
        }
        $this->assertPercentageStorageRange($pdo, 'impuesto', 'tasa_impuesto', $rate, 'Impuesto tasa', $rowNumber);
        $cacheKey = $this->normalizeKey($name) . '|' . number_format($rate, 6, '.', '');
        if (array_key_exists($cacheKey, $this->taxCache)) {
            return $this->taxCache[$cacheKey];
        }

        $st = $pdo->prepare("
            SELECT id_impuesto
            FROM pos_saas.impuesto
            WHERE id_empresa = :e
              AND lower(btrim(nombre_impuesto)) = lower(btrim(:n))
              AND tasa_impuesto = :r
            LIMIT 1
        ");
        $st->execute([':e' => $empresaId, ':n' => $name, ':r' => $rate]);
        $existing = $st->fetchColumn();
        if ($existing !== false) {
            $this->taxCache[$cacheKey] = (int)$existing;
            return (int)$existing;
        }

        $st = $pdo->prepare("
            INSERT INTO pos_saas.impuesto (
                id_empresa, nombre_impuesto, tasa_impuesto, tipo_impuesto, estado, created_at, updated_at
            )
            VALUES (:e, :n, :r, 'PORCENTAJE', 1, now(), now())
            RETURNING id_impuesto
        ");
        $st->execute([':e' => $empresaId, ':n' => $name, ':r' => $rate]);
        $created = (int)$st->fetchColumn();
        $this->taxCache[$cacheKey] = $created;
        return $created;
    }

    private function findByName(PDO $pdo, string $table, string $idColumn, string $nameColumn, int $empresaId, string $name): ?int
    {
        $sql = 'SELECT ' . $idColumn . ' FROM ' . $table . ' WHERE id_empresa = :e AND lower(btrim(' . $nameColumn . ')) = lower(btrim(:n)) LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([':e' => $empresaId, ':n' => $name]);
        $value = $st->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    private function hasPresentationTable(PDO $pdo): bool
    {
        if ($this->hasPresentationTable !== null) {
            return $this->hasPresentationTable;
        }

        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'pos_saas'
              AND table_name = 'producto_presentacion'
            LIMIT 1
        ");
        $st->execute();
        $this->hasPresentationTable = (bool)$st->fetchColumn();

        return $this->hasPresentationTable;
    }

    private function prepareLongImportRuntime(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');
    }

    private function resetCaches(): void
    {
        $this->hasPresentationTable = null;
        $this->numericColumnMaxCache = [];
        $this->categoryCache = [];
        $this->providerCache = [];
        $this->unitCache = [];
        $this->taxCache = [];
    }

    private function tenantConnection(PDO $control, int $empresaId): PDO
    {
        $tenant = BusinessConfigService::getTenantMapping($control, $empresaId);
        if (!$tenant || trim((string)($tenant['db_name'] ?? '')) === '') {
            throw new RuntimeException('La empresa no tiene tenant configurado.');
        }

        try {
            return Database::connectTenant((string)$tenant['db_name'], $tenant);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DB_TENANT_CREDENTIALS_REQUIRED') {
                throw $e;
            }
            throw new RuntimeException('No se pudo conectar al tenant de la empresa.');
        }
    }

    private function clean(string $value): string
    {
        return trim(str_replace("\xC2\xA0", ' ', $value));
    }

    private function nullable(string $value): ?string
    {
        $value = $this->clean($value);
        return $value === '' ? null : $value;
    }

    private function normalizeKey(string $value): string
    {
        return strtolower($this->clean($value));
    }

    private function number(string $value, string $label, int $rowNumber): float
    {
        $number = $this->parseNumber($value);
        if ($number === null) {
            throw new RuntimeException('Fila ' . $rowNumber . ': ' . $label . ' debe ser numerico.');
        }
        return $number;
    }

    private function assertPositive(float $value, string $label, int $rowNumber): void
    {
        if ($value <= 0) {
            throw new RuntimeException('Fila ' . $rowNumber . ': ' . $label . ' debe ser mayor a 0.');
        }
    }

    private function assertPercentageStorageRange(PDO $pdo, string $table, string $column, float $value, string $label, int $rowNumber): void
    {
        $storageMax = $this->numericColumnMax($pdo, $table, $column);
        if ($storageMax !== null && abs(round($value, 2)) > $storageMax) {
            throw new RuntimeException('Fila ' . $rowNumber . ': ' . $label . ' supera el maximo soportado por la base de datos: ' . $this->formatDecimal($storageMax) . '.');
        }
    }

    private function numericColumnMax(PDO $pdo, string $table, string $column): ?float
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->numericColumnMaxCache)) {
            return $this->numericColumnMaxCache[$cacheKey];
        }

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
            $this->numericColumnMaxCache[$cacheKey] = null;
            return null;
        }

        $integerDigits = (int)$row['numeric_precision'] - (int)$row['numeric_scale'];
        if ($integerDigits <= 0) {
            $this->numericColumnMaxCache[$cacheKey] = null;
            return null;
        }

        $this->numericColumnMaxCache[$cacheKey] = (10 ** $integerDigits) - (10 ** (-(int)$row['numeric_scale']));
        return $this->numericColumnMaxCache[$cacheKey];
    }

    private function calculateMargin(float $costo, float $precio, int $rowNumber): float
    {
        if ($costo <= 0) {
            throw new RuntimeException('Fila ' . $rowNumber . ': utilidad no se puede calcular con costo_unitario menor o igual a 0.');
        }
        if ($precio <= 0) {
            throw new RuntimeException('Fila ' . $rowNumber . ': utilidad no se puede calcular con precio menor o igual a 0.');
        }

        return round((($precio - $costo) / $costo) * 100, 6);
    }

    private function formatDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private function parseNumber(string $value): ?float
    {
        $raw = $this->clean($value);
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

    private function normalizeTipoCantidad(string $value): string
    {
        $v = strtoupper($this->clean($value));
        if ($v === '' || in_array($v, ['UNIDAD', 'UND', 'UNI'], true)) {
            return 'UNIDAD';
        }
        if (in_array($v, ['PESO', 'KG', 'KILOGRAMO', 'KILOGRAMOS'], true)) {
            return 'PESO';
        }
        return $v;
    }

    private function yesNo(string $value): ?bool
    {
        $v = strtoupper($this->clean($value));
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
        $v = $this->clean($value);
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

    private function defaultUnitSymbol(string $name): string
    {
        $n = strtoupper($name);
        if (str_contains($n, 'KILO')) {
            return 'KG';
        }
        if (str_contains($n, 'GRAM')) {
            return 'G';
        }
        if (str_contains($n, 'LITRO')) {
            return 'L';
        }
        if (str_contains($n, 'UNIDAD')) {
            return 'UND';
        }
        return substr(preg_replace('/[^A-Z0-9]/', '', $n) ?: 'UND', 0, 3);
    }

    private function friendlyDbError(Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'ux_prod_emp_sku')) {
            return 'hay un SKU duplicado.';
        }
        if (str_contains($message, 'ux_prod_emp_codigobarras')) {
            return 'hay un codigo de barras duplicado.';
        }
        if (str_contains($message, 'ux_producto_presentacion')) {
            return 'hay una presentacion duplicada.';
        }
        return $message;
    }

}

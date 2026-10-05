<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;
use PosAdmin\Core\Database;

final class TenantHealthService
{
    /** @var array<string, array<string, true>> */
    private array $tableCache = [];

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    /** @var array<string, int> */
    private array $catalogCountCache = [];

    private ?bool $snapshotTableAvailable = null;

    private const SNAPSHOT_TTL_SECONDS = 900;

    /** @var list<string> */
    private array $requiredTables = [
        'empresa',
        'usuario',
        'modulo',
        'permiso',
        'capacidad',
        'empresa_capacidad',
        'producto',
        'inventario',
        'venta',
        'venta_detalle',
        'compra',
        'compra_detalle',
    ];

    /** @var list<string> */
    private array $trackedTables = [
        'producto',
        'inventario',
        'venta',
        'venta_detalle',
        'venta_pago',
        'compra',
        'compra_detalle',
        'movimientos_inventario',
        'caja_movimiento',
        'deuda_credito',
        'deuda_pago',
        'venta_devolucion',
        'venta_devolucion_detalle',
        'soporte_orden',
    ];

    /** @var list<string> */
    private array $companyScopedTables = [
        'empresa',
        'usuario',
        'producto',
        'inventario',
        'venta',
        'venta_detalle',
        'venta_pago',
        'compra',
        'compra_detalle',
        'movimientos_inventario',
        'caja_movimiento',
        'deuda_credito',
        'deuda_pago',
        'venta_devolucion',
        'venta_devolucion_detalle',
        'soporte_orden',
    ];

    public function list(array $params = []): array
    {
        $control = Database::getConnection();
        $q = trim((string)($params['q'] ?? ''));
        $limit = max(1, min(50, (int)($params['limit'] ?? 25)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = "(
                e.nombre ILIKE :q
                OR e.codigo ILIKE :q
                OR e.nit ILIKE :q
                OR CAST(e.id_empresa AS text) = :q_exact
                OR t.db_name ILIKE :q
            )";
            $bind[':q'] = '%' . $q . '%';
            $bind[':q_exact'] = $q;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $control->prepare("SELECT COUNT(*) FROM pos_saas.empresa e LEFT JOIN admin.tenant_database t ON t.id_empresa = e.id_empresa{$whereSql}");
        foreach ($bind as $key => $value) {
            $count->bindValue($key, $value);
        }
        $count->execute();
        $total = (int)$count->fetchColumn();

        $snapshotAvailable = $this->snapshotTableExists($control);
        $snapshotJoin = $snapshotAvailable
            ? 'LEFT JOIN admin.tenant_health_snapshot h ON h.id_empresa = e.id_empresa'
            : '';
        $snapshotFields = $snapshotAvailable
            ? ', h.health_status AS snapshot_health_status, h.checked_at AS snapshot_checked_at, h.check_ms AS snapshot_check_ms, h.connection_ms AS snapshot_connection_ms, h.payload::text AS snapshot_payload'
            : ', NULL::text AS snapshot_health_status, NULL::timestamptz AS snapshot_checked_at, NULL::int AS snapshot_check_ms, NULL::int AS snapshot_connection_ms, NULL::text AS snapshot_payload';

        $sql = "
            SELECT
                e.id_empresa,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                e.nit,
                e.estado AS empresa_estado,
                e.tipo_negocio,
                e.created_at AS empresa_created_at,
                t.modo,
                t.db_host,
                t.db_port,
                t.db_name,
                t.db_schema,
                t.db_user,
                t.estado AS tenant_estado,
                t.created_at AS tenant_created_at,
                t.updated_at AS tenant_updated_at
                {$snapshotFields}
            FROM pos_saas.empresa e
            LEFT JOIN admin.tenant_database t
              ON t.id_empresa = e.id_empresa
            {$snapshotJoin}
            {$whereSql}
            ORDER BY e.id_empresa DESC
            LIMIT :limit OFFSET :offset
        ";
        $st = $control->prepare($sql);
        foreach ($bind as $key => $value) {
            $st->bindValue($key, $value);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = $this->snapshotListItem($row, $snapshotAvailable);
        }

        $summary = [
            'ok' => 0,
            'warning' => 0,
            'error' => 0,
            'pending' => 0,
            'stale' => 0,
        ];
        foreach ($items as $item) {
            $status = strtolower((string)($item['health_status'] ?? 'error'));
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            $inspectionState = strtolower((string)($item['inspection']['state'] ?? ''));
            if ($inspectionState === 'stale') {
                $summary['stale']++;
            }
        }

        return [
            'ok' => true,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'q' => $q,
            'snapshot_supported' => $snapshotAvailable,
            'snapshot_ttl_seconds' => self::SNAPSHOT_TTL_SECONDS,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    public function show(int $companyId, bool $deep = false): array
    {
        $control = Database::getConnection();
        $st = $control->prepare('
            SELECT
                e.id_empresa,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                e.nit,
                e.estado AS empresa_estado,
                e.tipo_negocio,
                e.created_at AS empresa_created_at,
                t.modo,
                t.db_host,
                t.db_port,
                t.db_name,
                t.db_schema,
                t.db_user,
                t.estado AS tenant_estado,
                t.created_at AS tenant_created_at,
                t.updated_at AS tenant_updated_at
            FROM pos_saas.empresa e
            LEFT JOIN admin.tenant_database t
              ON t.id_empresa = e.id_empresa
            WHERE e.id_empresa = :empresa
            LIMIT 1
        ');
        $st->execute([':empresa' => $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('EMPRESA_NOT_FOUND');
        }

        $item = $this->inspect($control, $row, $deep);
        $stored = $this->storeSnapshot($control, $item);
        $item['inspection'] = [
            'state' => 'FRESH',
            'source' => 'DIRECT',
            'deep' => $deep,
            'persisted' => $stored,
            'checked_at' => $item['checked_at'],
            'ttl_seconds' => self::SNAPSHOT_TTL_SECONDS,
        ];

        return [
            'ok' => true,
            'item' => $item,
        ];
    }

    /**
     * Ejecuta sondeos vencidos desde una tarea programada. El listado HTTP no
     * abre conexiones tenant; solo consume estas instantaneas.
     *
     * @return array<string, mixed>
     */
    public function refreshDue(int $limit = 20, bool $deep = false): array
    {
        $control = Database::getConnection();
        if (!$this->snapshotTableExists($control)) {
            throw new \RuntimeException('TENANT_HEALTH_SNAPSHOT_TABLE_NOT_FOUND');
        }

        $locked = (bool)$control->query("SELECT pg_try_advisory_lock(hashtext('pos_admin_tenant_health_refresh'))")->fetchColumn();
        if (!$locked) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'ALREADY_RUNNING', 'items' => []];
        }

        try {
            $limit = max(1, min(50, $limit));
            $st = $control->prepare('
                SELECT
                    e.id_empresa,
                    e.nombre AS empresa_nombre,
                    e.codigo AS empresa_codigo,
                    e.nit,
                    e.estado AS empresa_estado,
                    e.tipo_negocio,
                    e.created_at AS empresa_created_at,
                    t.modo,
                    t.db_host,
                    t.db_port,
                    t.db_name,
                    t.db_schema,
                    t.db_user,
                    t.estado AS tenant_estado,
                    t.created_at AS tenant_created_at,
                    t.updated_at AS tenant_updated_at
                FROM pos_saas.empresa e
                INNER JOIN admin.tenant_database t ON t.id_empresa = e.id_empresa
                LEFT JOIN admin.tenant_health_snapshot h ON h.id_empresa = e.id_empresa
                WHERE h.id_empresa IS NULL
                   OR h.checked_at < now() - make_interval(secs => :ttl)
                ORDER BY h.checked_at ASC NULLS FIRST, e.id_empresa ASC
                LIMIT :limit
            ');
            $st->bindValue(':ttl', self::SNAPSHOT_TTL_SECONDS, PDO::PARAM_INT);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();

            $items = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $item = $this->inspect($control, $row, $deep);
                $this->storeSnapshot($control, $item);
                $items[] = [
                    'id_empresa' => (int)$item['id_empresa'],
                    'health_status' => (string)$item['health_status'],
                    'check_ms' => (int)$item['check_ms'],
                ];
            }

            return ['ok' => true, 'skipped' => false, 'items' => $items];
        } finally {
            $control->query("SELECT pg_advisory_unlock(hashtext('pos_admin_tenant_health_refresh'))");
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function inspect(PDO $control, array $row, bool $deep): array
    {
        $startedAt = microtime(true);
        $companyId = (int)($row['id_empresa'] ?? 0);
        $schema = trim((string)($row['db_schema'] ?? 'pos_saas')) ?: 'pos_saas';

        $out = $this->baseHealthOutput($row);

        $dbName = trim((string)($row['db_name'] ?? ''));
        if ($dbName === '') {
            $out['errors'][] = 'Empresa sin tenant asignado en admin.tenant_database.';
            return $this->finish($out, $startedAt);
        }

        $tenantState = strtoupper(trim((string)($row['tenant_estado'] ?? '')));
        if ($tenantState !== 'ACTIVE') {
            $out['warnings'][] = 'Tenant no esta ACTIVE en admin.tenant_database.';
        }

        try {
            $connectStart = microtime(true);
            $tenant = $this->connectTenantDatabase($dbName, $row);
            $out['connection_ms'] = (int)round((microtime(true) - $connectStart) * 1000);

            if (!$this->schemaExists($tenant, $schema)) {
                $out['errors'][] = "Schema {$schema} no existe en {$dbName}.";
                return $this->finish($out, $startedAt);
            }

            foreach ($this->requiredTables as $table) {
                if (!$this->tableExists($tenant, $schema, $table)) {
                    $out['errors'][] = "Falta tabla {$schema}.{$table}.";
                }
            }

            $out['db_size_bytes'] = (int)$tenant->query('SELECT pg_database_size(current_database())')->fetchColumn();
            $out['db_size'] = $this->prettyBytes((int)$out['db_size_bytes']);
            $stats = $this->tableStats($tenant, $schema);
            $out['counts'] = $this->estimatedCounts($stats);
            $out['recent'] = $this->recentActivity($tenant, $schema, $companyId);
            $out['top_tables'] = $this->topTables($stats, 5);

            $this->checkCompanyMirror($control, $tenant, $schema, $row, $out);
            $this->checkCatalogCounts($control, $tenant, $schema, $out);

            if ($deep) {
                $this->checkCompanyIsolation($tenant, $schema, $companyId, $out);
            }

            $this->addGrowthWarnings($out, $stats);
        } catch (\Throwable $e) {
            $out['errors'][] = 'No se pudo conectar o inspeccionar tenant: ' . $e->getMessage();
        }

        return $this->finish($out, $startedAt);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function baseHealthOutput(array $row): array
    {
        $schema = trim((string)($row['db_schema'] ?? 'pos_saas')) ?: 'pos_saas';
        return [
            'id_empresa' => (int)($row['id_empresa'] ?? 0),
            'empresa_nombre' => (string)($row['empresa_nombre'] ?? ''),
            'empresa_codigo' => $row['empresa_codigo'] ?? null,
            'empresa_estado' => (int)($row['empresa_estado'] ?? 0),
            'tipo_negocio' => (string)($row['tipo_negocio'] ?? 'GENERAL'),
            'tenant' => [
                'modo' => $row['modo'] ?? null,
                'db_host' => $row['db_host'] ?? null,
                'db_port' => $row['db_port'] ?? null,
                'db_name' => $row['db_name'] ?? null,
                'db_schema' => $schema,
                'db_user' => $row['db_user'] ?? null,
                'estado' => $row['tenant_estado'] ?? null,
                'created_at' => $row['tenant_created_at'] ?? null,
                'updated_at' => $row['tenant_updated_at'] ?? null,
            ],
            'health_status' => 'OK',
            'connection_ms' => null,
            'db_size_bytes' => 0,
            'db_size' => '-',
            'counts' => [],
            'recent' => [],
            'warnings' => [],
            'errors' => [],
            'checked_at' => gmdate('c'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function snapshotListItem(array $row, bool $snapshotAvailable): array
    {
        $item = $this->baseHealthOutput($row);
        $payload = $this->decodeSnapshotPayload((string)($row['snapshot_payload'] ?? ''));
        foreach (['health_status', 'connection_ms', 'db_size_bytes', 'db_size', 'counts', 'recent', 'warnings', 'errors', 'checked_at', 'check_ms', 'top_tables'] as $field) {
            if (array_key_exists($field, $payload)) {
                $item[$field] = $payload[$field];
            }
        }

        $checkedAt = trim((string)($row['snapshot_checked_at'] ?? ''));
        $checkedUnix = $checkedAt !== '' ? strtotime($checkedAt) : false;
        $state = 'PENDING';
        if ($snapshotAvailable && $checkedUnix !== false) {
            $state = (time() - $checkedUnix) > self::SNAPSHOT_TTL_SECONDS ? 'STALE' : 'FRESH';
        }

        if ($state === 'PENDING') {
            $item['health_status'] = 'PENDING';
            $item['checked_at'] = null;
        } else {
            $item['health_status'] = strtoupper((string)($row['snapshot_health_status'] ?? $item['health_status'] ?? 'ERROR'));
            $item['checked_at'] = $checkedAt;
            $item['connection_ms'] = $row['snapshot_connection_ms'] ?? $item['connection_ms'];
            $item['check_ms'] = $row['snapshot_check_ms'] ?? $item['check_ms'];
        }

        $item['inspection'] = [
            'state' => $state,
            'source' => $state === 'PENDING' ? 'NOT_CHECKED' : 'SNAPSHOT',
            'deep' => false,
            'persisted' => $snapshotAvailable,
            'checked_at' => $item['checked_at'],
            'ttl_seconds' => self::SNAPSHOT_TTL_SECONDS,
        ];

        return $item;
    }

    /** @return array<string, mixed> */
    private function decodeSnapshotPayload(string $payload): array
    {
        if ($payload === '') {
            return [];
        }
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param array<string, mixed> $item */
    private function storeSnapshot(PDO $control, array $item): bool
    {
        if (!$this->snapshotTableExists($control)) {
            return false;
        }

        try {
            $payload = json_encode($item, JSON_THROW_ON_ERROR);
            $st = $control->prepare('
                INSERT INTO admin.tenant_health_snapshot
                    (id_empresa, health_status, checked_at, connection_ms, check_ms, payload, updated_at)
                VALUES
                    (:empresa, :status, :checked_at, :connection_ms, :check_ms, CAST(:payload AS jsonb), now())
                ON CONFLICT (id_empresa) DO UPDATE SET
                    health_status = EXCLUDED.health_status,
                    checked_at = EXCLUDED.checked_at,
                    connection_ms = EXCLUDED.connection_ms,
                    check_ms = EXCLUDED.check_ms,
                    payload = EXCLUDED.payload,
                    updated_at = now()
            ');
            $st->execute([
                ':empresa' => (int)$item['id_empresa'],
                ':status' => (string)$item['health_status'],
                ':checked_at' => (string)$item['checked_at'],
                ':connection_ms' => $item['connection_ms'] !== null ? (int)$item['connection_ms'] : null,
                ':check_ms' => (int)$item['check_ms'],
                ':payload' => $payload,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function snapshotTableExists(PDO $control): bool
    {
        if ($this->snapshotTableAvailable !== null) {
            return $this->snapshotTableAvailable;
        }
        $this->snapshotTableAvailable = (bool)$control->query("SELECT to_regclass('admin.tenant_health_snapshot') IS NOT NULL")->fetchColumn();
        return $this->snapshotTableAvailable;
    }

    /** @param array<string, mixed> $out */
    private function finish(array $out, float $startedAt): array
    {
        $out['check_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
        if (!empty($out['errors'])) {
            $out['health_status'] = 'ERROR';
        } elseif (!empty($out['warnings'])) {
            $out['health_status'] = 'WARNING';
        } else {
            $out['health_status'] = 'OK';
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function connectTenantDatabase(string $database, array $row): PDO
    {
        return Database::connectTenant($database, $row, [PDO::ATTR_TIMEOUT => 4]);
    }

    private function checkCompanyMirror(PDO $control, PDO $tenant, string $schema, array $row, array &$out): void
    {
        if (!$this->tableExists($tenant, $schema, 'empresa')) {
            return;
        }

        $companyId = (int)$row['id_empresa'];
        $tenantCompany = $this->fetchOne(
            $tenant,
            "SELECT nombre, estado, tipo_negocio FROM {$this->qt($schema, 'empresa')} WHERE id_empresa = :e LIMIT 1",
            [':e' => $companyId]
        );
        if ($tenantCompany === null) {
            $out['errors'][] = "Tenant no tiene espejo de empresa {$companyId}.";
            return;
        }

        $controlCompany = $this->fetchOne(
            $control,
            'SELECT nombre, estado, tipo_negocio FROM pos_saas.empresa WHERE id_empresa = :e LIMIT 1',
            [':e' => $companyId]
        );
        if ($controlCompany === null) {
            return;
        }

        foreach (['nombre', 'estado', 'tipo_negocio'] as $field) {
            if ((string)($controlCompany[$field] ?? '') !== (string)($tenantCompany[$field] ?? '')) {
                $out['warnings'][] = "Tenant difiere de control en empresa.{$field}.";
            }
        }
    }

    private function checkCatalogCounts(PDO $control, PDO $tenant, string $schema, array &$out): void
    {
        foreach (['modulo', 'permiso', 'capacidad'] as $table) {
            if (!$this->tableExists($control, 'pos_saas', $table) || !$this->tableExists($tenant, $schema, $table)) {
                continue;
            }
            $cacheKey = 'pos_saas.' . $table;
            if (!isset($this->catalogCountCache[$cacheKey])) {
                $this->catalogCountCache[$cacheKey] = $this->countTable($control, 'pos_saas', $table);
            }
            $controlCount = $this->catalogCountCache[$cacheKey];
            $tenantCount = $this->countTable($tenant, $schema, $table);
            if ($controlCount !== $tenantCount) {
                $out['warnings'][] = "Catalogo {$table} difiere: control={$controlCount}, tenant={$tenantCount}.";
            }
        }
    }

    private function checkCompanyIsolation(PDO $tenant, string $schema, int $companyId, array &$out): void
    {
        foreach ($this->companyScopedTables as $table) {
            if (!$this->tableExists($tenant, $schema, $table) || !$this->columnExists($tenant, $schema, $table, 'id_empresa')) {
                continue;
            }
            $count = $this->countWhere($tenant, $schema, $table, 'id_empresa <> :e', [':e' => $companyId]);
            if ($count > 0) {
                $out['errors'][] = "{$table} tiene {$count} fila(s) de otra empresa.";
            }
        }
    }

    private function addGrowthWarnings(array &$out, array $stats): void
    {
        if ((int)($out['db_size_bytes'] ?? 0) >= 10 * 1024 * 1024 * 1024) {
            $out['warnings'][] = 'Base mayor o igual a 10 GB; revisar backups, indices y crecimiento.';
        }
        if ((int)($out['counts']['venta_detalle'] ?? 0) >= 1000000) {
            $out['warnings'][] = 'venta_detalle supera 1.000.000 filas; revisar reportes e historico.';
        }
        if ((int)($out['counts']['movimientos_inventario'] ?? 0) >= 1000000) {
            $out['warnings'][] = 'movimientos_inventario supera 1.000.000 filas; revisar indices y mantenimiento.';
        }

        foreach ($stats as $stat) {
            $live = max(0, (int)($stat['live_rows'] ?? 0));
            $dead = max(0, (int)($stat['dead_rows'] ?? 0));
            if ($dead >= 10000 && $dead > ($live * 0.25)) {
                $out['warnings'][] = (string)$stat['table'] . ' tiene muchas filas muertas estimadas; revisar autovacuum/analyze.';
            }
        }
    }

    private function recentActivity(PDO $pdo, string $schema, int $companyId): array
    {
        $recent = [
            'ventas_30d' => 0,
            'total_ventas_30d' => 0.0,
            'compras_30d' => 0,
            'movimientos_inv_30d' => 0,
            'caja_movimientos_30d' => 0,
        ];

        if ($this->tableExists($pdo, $schema, 'venta')) {
            $paidFilter = $this->columnExists($pdo, $schema, 'venta', 'estado')
                ? "\n                  AND UPPER(COALESCE(estado, '')) = 'PAID'"
                : '';
            $row = $this->fetchOne($pdo, "
                SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS total
                FROM {$this->qt($schema, 'venta')}
                WHERE id_empresa = :e
                  AND fecha >= now() - interval '30 days'{$paidFilter}
            ", [':e' => $companyId]);
            $recent['ventas_30d'] = (int)($row['c'] ?? 0);
            $recent['total_ventas_30d'] = (float)($row['total'] ?? 0);
        }

        if ($this->tableExists($pdo, $schema, 'compra')) {
            $recent['compras_30d'] = $this->countRecent($pdo, $schema, 'compra', 'fecha_doc', $companyId);
        }
        if ($this->tableExists($pdo, $schema, 'movimientos_inventario')) {
            $recent['movimientos_inv_30d'] = $this->countRecent($pdo, $schema, 'movimientos_inventario', 'fecha', $companyId);
        }
        if ($this->tableExists($pdo, $schema, 'caja_movimiento')) {
            $recent['caja_movimientos_30d'] = $this->countRecent($pdo, $schema, 'caja_movimiento', 'created_at', $companyId);
        }

        return $recent;
    }

    private function countRecent(PDO $pdo, string $schema, string $table, string $dateColumn, int $companyId): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE id_empresa = :e AND %s >= now() - interval %s',
            $this->qt($schema, $table),
            $this->qi($dateColumn),
            $pdo->quote('30 days')
        );
        $st = $pdo->prepare($sql);
        $st->execute([':e' => $companyId]);
        return (int)$st->fetchColumn();
    }

    private function tableStats(PDO $pdo, string $schema): array
    {
        $in = implode(',', array_fill(0, count($this->trackedTables), '?'));
        $sql = "
            SELECT
                relname AS table_name,
                n_live_tup,
                n_dead_tup,
                pg_total_relation_size(relid) AS total_bytes,
                pg_size_pretty(pg_total_relation_size(relid)) AS total_size
            FROM pg_stat_user_tables
            WHERE schemaname = ?
              AND relname IN ({$in})
        ";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$schema], $this->trackedTables));

        $stats = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $table = (string)$row['table_name'];
            $stats[$table] = [
                'table' => $table,
                'live_rows' => (int)$row['n_live_tup'],
                'dead_rows' => (int)$row['n_dead_tup'],
                'total_bytes' => (int)$row['total_bytes'],
                'total_size' => (string)$row['total_size'],
            ];
        }
        return $stats;
    }

    private function estimatedCounts(array $stats): array
    {
        $counts = [];
        foreach ($this->trackedTables as $table) {
            $counts[$table] = (int)($stats[$table]['live_rows'] ?? 0);
        }
        return $counts;
    }

    private function topTables(array $stats, int $limit): array
    {
        $rows = array_values($stats);
        usort($rows, static fn(array $a, array $b) => ((int)$b['total_bytes']) <=> ((int)$a['total_bytes']));
        return array_slice($rows, 0, $limit);
    }

    private function schemaExists(PDO $pdo, string $schema): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = :s LIMIT 1');
        $st->execute([':s' => $schema]);
        return (bool)$st->fetchColumn();
    }

    private function tableExists(PDO $pdo, string $schema, string $table): bool
    {
        $key = spl_object_id($pdo) . ':' . $schema;
        if (!isset($this->tableCache[$key])) {
            $st = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = :s');
            $st->execute([':s' => $schema]);
            $this->tableCache[$key] = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                $this->tableCache[$key][(string)$name] = true;
            }
        }
        return isset($this->tableCache[$key][$table]);
    }

    private function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $key = spl_object_id($pdo) . ':' . $schema;
        if (!isset($this->columnCache[$key])) {
            $st = $pdo->prepare('
                SELECT table_name, column_name
                FROM information_schema.columns
                WHERE table_schema = :s
            ');
            $st->execute([':s' => $schema]);
            $this->columnCache[$key] = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $tableName = (string)($row['table_name'] ?? '');
                $columnName = (string)($row['column_name'] ?? '');
                if ($tableName !== '' && $columnName !== '') {
                    $this->columnCache[$key][$tableName . '.' . $columnName] = true;
                }
            }
        }
        return isset($this->columnCache[$key][$table . '.' . $column]);
    }

    private function countTable(PDO $pdo, string $schema, string $table): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM {$this->qt($schema, $table)}")->fetchColumn();
    }

    private function countWhere(PDO $pdo, string $schema, string $table, string $where, array $params): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM {$this->qt($schema, $table)} WHERE {$where}");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    private function fetchOne(PDO $pdo, string $sql, array $params): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function qt(string $schema, string $table): string
    {
        return $this->qi($schema) . '.' . $this->qi($table);
    }

    private function qi(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function prettyBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024) {
                return number_format($value, 1, ',', '.') . ' ' . $unit;
            }
            $value /= 1024;
        }
        return number_format($value, 1, ',', '.') . ' PB';
    }
}

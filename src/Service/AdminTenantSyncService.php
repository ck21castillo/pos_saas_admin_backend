<?php
namespace PosAdmin\Service;

use PDO;
use PosAdmin\Core\Database;

final class AdminTenantSyncService
{
    public function syncCompany(int $companyId): void
    {
        $control = Database::getConnection();
        $tenant = $this->tenantConnection($control, $companyId);
        if ($tenant === null) {
            return;
        }

        $started = !$tenant->inTransaction();
        if ($started) {
            $tenant->beginTransaction();
        }

        try {
            $this->syncCompanyRow($control, $tenant, $companyId);
            $this->resetSequence($tenant, 'pos_saas.empresa', 'id_empresa');

            if ($started) {
                $tenant->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $tenant->inTransaction()) {
                $tenant->rollBack();
            }
            throw $e;
        }
    }

    public function syncBusinessConfig(int $companyId): void
    {
        $control = Database::getConnection();
        $tenant = $this->tenantConnection($control, $companyId);
        if ($tenant === null) {
            return;
        }

        $started = !$tenant->inTransaction();
        if ($started) {
            $tenant->beginTransaction();
        }

        try {
            $this->syncCompanyRow($control, $tenant, $companyId);
            $this->syncCompanyCapabilities($control, $tenant, $companyId);
            $this->resetSequence($tenant, 'pos_saas.empresa', 'id_empresa');

            if ($started) {
                $tenant->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $tenant->inTransaction()) {
                $tenant->rollBack();
            }
            throw $e;
        }
    }

    private function syncCompanyRow(PDO $control, PDO $tenant, int $companyId): void
    {
        $st = $control->prepare('
            SELECT
                id_empresa, nombre, codigo, created_at, updated_at, estado,
                nit, direccion, telefono, logo_empresa,
                codigo_departamento, codigo_municipio, barrio, tipo_negocio
            FROM pos_saas.empresa
            WHERE id_empresa = :e
            LIMIT 1
        ');
        $st->execute([':e' => $companyId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('TENANT_COMPANY_SOURCE_NOT_FOUND');
        }

        $up = $tenant->prepare('
            INSERT INTO pos_saas.empresa (
                id_empresa, nombre, codigo, created_at, updated_at, estado,
                nit, direccion, telefono, logo_empresa,
                codigo_departamento, codigo_municipio, barrio, tipo_negocio
            )
            VALUES (
                :id_empresa, :nombre, :codigo, :created_at, :updated_at, :estado,
                :nit, :direccion, :telefono, :logo_empresa,
                :codigo_departamento, :codigo_municipio, :barrio, :tipo_negocio
            )
            ON CONFLICT (id_empresa) DO UPDATE SET
                nombre = EXCLUDED.nombre,
                codigo = EXCLUDED.codigo,
                updated_at = EXCLUDED.updated_at,
                estado = EXCLUDED.estado,
                nit = EXCLUDED.nit,
                direccion = EXCLUDED.direccion,
                telefono = EXCLUDED.telefono,
                logo_empresa = EXCLUDED.logo_empresa,
                codigo_departamento = EXCLUDED.codigo_departamento,
                codigo_municipio = EXCLUDED.codigo_municipio,
                barrio = EXCLUDED.barrio,
                tipo_negocio = EXCLUDED.tipo_negocio
        ');
        $up->execute([
            ':id_empresa' => (int)$row['id_empresa'],
            ':nombre' => (string)$row['nombre'],
            ':codigo' => (string)$row['codigo'],
            ':created_at' => $row['created_at'],
            ':updated_at' => $row['updated_at'],
            ':estado' => (int)$row['estado'],
            ':nit' => $row['nit'],
            ':direccion' => $row['direccion'],
            ':telefono' => $row['telefono'],
            ':logo_empresa' => $row['logo_empresa'],
            ':codigo_departamento' => $row['codigo_departamento'],
            ':codigo_municipio' => $row['codigo_municipio'],
            ':barrio' => $row['barrio'],
            ':tipo_negocio' => BusinessConfigService::normalizeBusinessType((string)$row['tipo_negocio']),
        ]);
    }

    private function syncCompanyCapabilities(PDO $control, PDO $tenant, int $companyId): void
    {
        $rows = $control->prepare('
            SELECT id_empresa, codigo_capacidad, enabled, created_at, updated_at
            FROM pos_saas.empresa_capacidad
            WHERE id_empresa = :e
        ');
        $rows->execute([':e' => $companyId]);

        $tenant->prepare('DELETE FROM pos_saas.empresa_capacidad WHERE id_empresa = :e')
            ->execute([':e' => $companyId]);

        $insert = $tenant->prepare('
            INSERT INTO pos_saas.empresa_capacidad
                (id_empresa, codigo_capacidad, enabled, created_at, updated_at)
            SELECT
                :e,
                c.codigo_capacidad,
                CAST(:enabled AS boolean),
                COALESCE(CAST(:created_at AS timestamptz), now()),
                COALESCE(CAST(:updated_at AS timestamptz), now())
            FROM pos_saas.capacidad c
            WHERE c.codigo_capacidad = :code
            ON CONFLICT (id_empresa, codigo_capacidad)
            DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at
        ');

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $insert->execute([
                ':e' => $companyId,
                ':code' => (string)$row['codigo_capacidad'],
                ':enabled' => BusinessConfigService::toBool($row['enabled'] ?? false) ? 'true' : 'false',
                ':created_at' => $row['created_at'] ?? null,
                ':updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    private function tenantConnection(PDO $control, int $companyId): ?PDO
    {
        $st = $control->prepare('
            SELECT db_host, db_port, db_name, db_user
            FROM admin.tenant_database
            WHERE id_empresa = :e
            LIMIT 1
        ');
        $st->execute([':e' => $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $database = trim((string)($row['db_name'] ?? ''));
        if (!$this->isSafeIdentifier($database)) {
            throw new \RuntimeException('TENANT_DATABASE_INVALID');
        }

        $host = trim((string)($_ENV['DB_TENANT_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($row['db_host'] ?? '')) ?: trim((string)($_ENV['DB_HOST'] ?? 'localhost'));
        }

        $port = trim((string)($_ENV['DB_TENANT_PORT'] ?? ''));
        if ($port === '') {
            $port = trim((string)($row['db_port'] ?? '')) ?: trim((string)($_ENV['DB_PORT'] ?? '5432'));
        }

        $user = trim((string)($_ENV['DB_TENANT_USER'] ?? ''));
        if ($user === '') {
            $user = trim((string)($row['db_user'] ?? '')) ?: trim((string)($_ENV['DB_USER'] ?? ''));
        }

        $password = array_key_exists('DB_TENANT_PASSWORD', $_ENV)
            ? (string)$_ENV['DB_TENANT_PASSWORD']
            : (string)($_ENV['DB_PASSWORD'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $host)) {
            throw new \RuntimeException('TENANT_HOST_INVALID');
        }
        if (!ctype_digit($port) || (int)$port <= 0) {
            throw new \RuntimeException('TENANT_PORT_INVALID');
        }
        if ($user === '') {
            throw new \RuntimeException('TENANT_USER_INVALID');
        }

        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
            $host,
            $port,
            $database
        );

        return new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function resetSequence(PDO $tenant, string $table, string $column): void
    {
        $seqSt = $tenant->prepare('SELECT pg_get_serial_sequence(:table_name, :column_name)');
        $seqSt->execute([':table_name' => $table, ':column_name' => $column]);

        $sequence = $seqSt->fetchColumn();
        if (!is_string($sequence) || $sequence === '') {
            return;
        }

        $max = (int)$tenant->query("SELECT COALESCE(MAX({$column}), 0) FROM {$table}")->fetchColumn();
        $set = $tenant->prepare('SELECT setval(CAST(:sequence_name AS regclass), :sequence_value, :is_called)');
        $set->execute([
            ':sequence_name' => $sequence,
            ':sequence_value' => max(1, $max),
            ':is_called' => $max > 0,
        ]);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }
}
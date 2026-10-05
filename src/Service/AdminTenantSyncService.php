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
            throw new \RuntimeException('TENANT_SYNC_SIN_CONFIGURACION');
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

    public function tenantConnectionForCompany(int $companyId): ?PDO
    {
        return $this->tenantConnection(Database::getConnection(), $companyId);
    }

    public function enqueueBusinessConfig(PDO $control, int $companyId): void
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('TENANT_SYNC_EMPRESA_INVALIDA');
        }

        $st = $control->prepare('
            INSERT INTO admin.tenant_sync_outbox
                (id_empresa, tipo, estado, intentos, ultimo_error, proximo_intento_at)
            VALUES (:empresa, \'BUSINESS_CONFIG\', \'PENDIENTE\', 0, NULL, now())
            ON CONFLICT (id_empresa, tipo) DO UPDATE SET
                estado = \'PENDIENTE\',
                ultimo_error = NULL,
                proximo_intento_at = now(),
                updated_at = now()
        ');
        $st->execute([':empresa' => $companyId]);
    }

    /** @return array<string,mixed> */
    public function processBusinessConfigForCompany(int $companyId): array
    {
        $control = Database::getConnection();
        $st = $control->prepare('
            SELECT id_tenant_sync_outbox
            FROM admin.tenant_sync_outbox
            WHERE id_empresa = :empresa AND tipo = \'BUSINESS_CONFIG\'
            LIMIT 1
        ');
        $st->execute([':empresa' => $companyId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id <= 0) {
            return ['estado' => 'SINCRONIZADO', 'id_empresa' => $companyId, 'detalle' => 'SIN_TAREA'];
        }
        return $this->processOutboxItem($id, true);
    }

    /** @return array<int,array<string,mixed>> */
    public function retryPendingBusinessConfigs(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $control = Database::getConnection();
        $st = $control->prepare('
            SELECT id_tenant_sync_outbox
            FROM admin.tenant_sync_outbox
            WHERE tipo = \'BUSINESS_CONFIG\'
              AND estado = \'PENDIENTE\'
              AND proximo_intento_at <= now()
            ORDER BY id_tenant_sync_outbox ASC
            LIMIT :limit
        ');
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $out[] = $this->processOutboxItem((int)$id, false);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function processOutboxItem(int $idOutbox, bool $force): array
    {
        $control = Database::getConnection();
        $control->beginTransaction();
        try {
            $st = $control->prepare('
                SELECT id_tenant_sync_outbox, id_empresa, estado, intentos, proximo_intento_at
                FROM admin.tenant_sync_outbox
                WHERE id_tenant_sync_outbox = :id
                FOR UPDATE SKIP LOCKED
            ');
            $st->execute([':id' => $idOutbox]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($item)) {
                $control->commit();
                return ['estado' => 'PENDIENTE', 'detalle' => 'TAREA_OCUPADA_O_INEXISTENTE'];
            }
            if (!$force && (string)$item['estado'] !== 'PENDIENTE') {
                $control->commit();
                return $this->syncStatus($item);
            }

            try {
                $this->syncBusinessConfig((int)$item['id_empresa']);
                $done = $control->prepare('
                    UPDATE admin.tenant_sync_outbox
                    SET estado = \'SINCRONIZADO\', intentos = intentos + 1,
                        ultimo_error = NULL, proximo_intento_at = NULL,
                        sincronizado_at = now(), updated_at = now()
                    WHERE id_tenant_sync_outbox = :id
                    RETURNING id_empresa, estado, intentos, ultimo_error, proximo_intento_at, sincronizado_at
                ');
                $done->execute([':id' => $idOutbox]);
                $result = $done->fetch(PDO::FETCH_ASSOC) ?: [];
                $control->commit();
                return $this->syncStatus($result);
            } catch (\Throwable $e) {
                $attempts = (int)$item['intentos'] + 1;
                $seconds = min(3600, max(60, 2 ** min($attempts, 12)));
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format(DATE_ATOM);
                $failed = $control->prepare('
                    UPDATE admin.tenant_sync_outbox
                    SET estado = \'PENDIENTE\', intentos = :intentos,
                        ultimo_error = :error, proximo_intento_at = CAST(:next AS timestamptz),
                        updated_at = now()
                    WHERE id_tenant_sync_outbox = :id
                    RETURNING id_empresa, estado, intentos, ultimo_error, proximo_intento_at, sincronizado_at
                ');
                $failed->execute([
                    ':id' => $idOutbox,
                    ':intentos' => $attempts,
                    ':error' => substr($e->getMessage(), 0, 1000),
                    ':next' => $next,
                ]);
                $result = $failed->fetch(PDO::FETCH_ASSOC) ?: [];
                $control->commit();
                return $this->syncStatus($result);
            }
        } catch (\Throwable $e) {
            if ($control->inTransaction()) {
                $control->rollBack();
            }
            return ['estado' => 'PENDIENTE', 'detalle' => 'OUTBOX_NO_PROCESADA', 'ultimo_error' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function syncStatus(array $row): array
    {
        return [
            'estado' => (string)($row['estado'] ?? 'PENDIENTE'),
            'id_empresa' => isset($row['id_empresa']) ? (int)$row['id_empresa'] : null,
            'intentos' => isset($row['intentos']) ? (int)$row['intentos'] : 0,
            'ultimo_error' => $row['ultimo_error'] ?? null,
            'proximo_intento_at' => $row['proximo_intento_at'] ?? null,
            'sincronizado_at' => $row['sincronizado_at'] ?? null,
        ];
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

        return Database::connectTenant((string)($row['db_name'] ?? ''), $row);
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
}

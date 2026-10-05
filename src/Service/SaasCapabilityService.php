<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

final class SaasCapabilityService
{
    public const MANAGED_CODES = [
        'SOPORTE_TECNICO',
        'PRECONTABILIDAD',
        'EXPORTACION_CONTABLE',
        'FACTURACION_ELECTRONICA',
        'NOTAS_FISCALES',
    ];

    private SupportCapabilityDiagnosticService $supportDiagnostic;

    public function __construct(?SupportCapabilityDiagnosticService $supportDiagnostic = null)
    {
        $this->supportDiagnostic = $supportDiagnostic ?? new SupportCapabilityDiagnosticService();
    }

    /** @return array<int,string> */
    public function managedCodes(): array
    {
        return self::MANAGED_CODES;
    }

    public function isManaged(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::MANAGED_CODES, true);
    }

    /** @return array<int,array<string,mixed>> */
    public function planCapabilities(PDO $pdo, int $idPlan): array
    {
        $st = $pdo->prepare('
            SELECT c.codigo_capacidad, c.nombre, c.descripcion,
                   COALESCE(pc.incluida, false) AS incluida
            FROM pos_saas.capacidad c
            LEFT JOIN admin.saas_plan_capacidad pc
              ON pc.codigo_capacidad = c.codigo_capacidad
             AND pc.id_plan = :plan
            WHERE c.codigo_capacidad = ANY(:codes::text[])
              AND c.estado = 1
            ORDER BY c.codigo_capacidad
        ');
        $st->execute([':plan' => $idPlan, ':codes' => '{' . implode(',', self::MANAGED_CODES) . '}']);
        return array_map(static fn (array $row): array => [
            'codigo_capacidad' => (string)$row['codigo_capacidad'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
            'incluida' => BusinessConfigService::toBool($row['incluida'] ?? false),
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<int,array<string,mixed>> */
    public function companyCapabilities(PDO $pdo, int $idEmpresa, bool $includeDiagnostics = true): array
    {
        $subscription = $pdo->prepare('SELECT id_plan FROM admin.saas_suscripcion WHERE id_empresa = :empresa LIMIT 1');
        $subscription->execute([':empresa' => $idEmpresa]);
        $idPlan = (int)($subscription->fetchColumn() ?: 0);
        $plan = [];
        if ($idPlan > 0) {
            foreach ($this->planCapabilities($pdo, $idPlan) as $row) {
                $plan[(string)$row['codigo_capacidad']] = (bool)$row['incluida'];
            }
        }

        $exceptions = $pdo->prepare('
            SELECT codigo_capacidad, enabled, motivo, created_at, updated_at
            FROM admin.saas_empresa_capacidad_excepcion
            WHERE id_empresa = :empresa AND activa = true
        ');
        $exceptions->execute([':empresa' => $idEmpresa]);
        $byCode = [];
        foreach ($exceptions->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byCode[(string)$row['codigo_capacidad']] = $row;
        }

        $catalog = $pdo->prepare('
            SELECT codigo_capacidad, nombre, descripcion
            FROM pos_saas.capacidad
            WHERE codigo_capacidad = ANY(:codes::text[]) AND estado = 1
            ORDER BY codigo_capacidad
        ');
        $catalog->execute([':codes' => '{' . implode(',', self::MANAGED_CODES) . '}']);
        $out = [];
        foreach ($catalog->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string)$row['codigo_capacidad'];
            $exception = $byCode[$code] ?? null;
            $enabled = $exception !== null
                ? BusinessConfigService::toBool($exception['enabled'] ?? false)
                : (bool)($plan[$code] ?? false);
            $diagnostic = $includeDiagnostics && $code === 'SOPORTE_TECNICO'
                ? $this->supportDiagnostic->diagnose($idEmpresa)
                : null;
            $out[] = [
                'codigo_capacidad' => $code,
                'nombre' => (string)$row['nombre'],
                'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
                'plan_incluida' => (bool)($plan[$code] ?? false),
                'incluido_en_plan' => (bool)($plan[$code] ?? false),
                'efectiva' => $enabled,
                'origen' => $exception !== null ? 'EXCEPCION_ADMIN' : ($idPlan > 0 ? 'PLAN' : 'SIN_SUSCRIPCION'),
                // The tenant may assign local roles only inside this commercial ceiling.
                'puede_configurar_localmente' => $enabled,
                'diagnostico_bloqueo' => !empty($diagnostic['bloquea_desactivacion']) ? $diagnostic : null,
                'excepcion' => $exception === null ? null : [
                    'enabled' => BusinessConfigService::toBool($exception['enabled'] ?? false),
                    'motivo' => (string)$exception['motivo'],
                    'updated_at' => $exception['updated_at'] ?? null,
                ],
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function savePlanCapabilities(PDO $pdo, int $idPlan, mixed $payload, int $actorId = 0, string $actorEmail = ''): array
    {
        $codes = $this->normalizeMap($payload);
        $this->validateDependencies($codes);
        $check = $pdo->prepare('SELECT 1 FROM admin.saas_plan WHERE id_plan = :plan LIMIT 1');
        $check->execute([':plan' => $idPlan]);
        if (!$check->fetchColumn()) {
            throw new \RuntimeException('PLAN_NO_ENCONTRADO');
        }

        $before = $this->planCapabilities($pdo, $idPlan);

        $delete = $pdo->prepare('DELETE FROM admin.saas_plan_capacidad WHERE id_plan = :plan');
        $delete->execute([':plan' => $idPlan]);
        $insert = $pdo->prepare('
            INSERT INTO admin.saas_plan_capacidad (id_plan, codigo_capacidad, incluida)
            VALUES (:plan, :code, true)
        ');
        foreach ($codes as $code => $included) {
            if ($included) {
                $insert->execute([':plan' => $idPlan, ':code' => $code]);
            }
        }
        $after = $this->planCapabilities($pdo, $idPlan);
        $this->auditPlanCapabilityMatrix($pdo, $actorId, $actorEmail, $idPlan, $before, $after);
        return $after;
    }

    /** @return array<int,array<string,mixed>> */
    public function saveException(PDO $pdo, int $idEmpresa, string $code, array $body, int $actorId, string $actorEmail): array
    {
        $code = strtoupper(trim($code));
        if (!$this->isManaged($code)) {
            throw new \InvalidArgumentException('CAPACIDAD_NO_GESTIONADA_POR_SAAS');
        }
        $motivo = trim((string)($body['motivo'] ?? ''));
        if ($motivo === '') {
            throw new \InvalidArgumentException('EXCEPCION_MOTIVO_REQUERIDO');
        }
        $restorePlan = BusinessConfigService::toBool($body['restablecer_plan'] ?? false);
        if (!$restorePlan && !array_key_exists('enabled', $body)) {
            throw new \InvalidArgumentException('EXCEPCION_ENABLED_REQUERIDO');
        }
        $before = $this->companyCapabilities($pdo, $idEmpresa);
        $candidate = [];
        $planValueForCode = false;
        foreach ($before as $row) {
            $rowCode = (string)$row['codigo_capacidad'];
            $candidate[$rowCode] = !empty($row['efectiva']);
            if ($rowCode === $code) {
                $planValueForCode = !empty($row['plan_incluida']);
            }
        }
        $candidate[$code] = $restorePlan
            ? $planValueForCode
            : BusinessConfigService::toBool($body['enabled'] ?? false);
        $this->validateDependencies($candidate);
        $up = $pdo->prepare('
            INSERT INTO admin.saas_empresa_capacidad_excepcion
                (id_empresa, codigo_capacidad, enabled, motivo, activa, creado_por, creado_por_email)
            VALUES (:empresa, :code, CAST(:enabled AS boolean), :motivo, CAST(:activa AS boolean), :actor, :email)
            ON CONFLICT (id_empresa, codigo_capacidad) DO UPDATE SET
                enabled = EXCLUDED.enabled,
                motivo = EXCLUDED.motivo,
                activa = EXCLUDED.activa,
                creado_por = EXCLUDED.creado_por,
                creado_por_email = EXCLUDED.creado_por_email,
                updated_at = now()
        ');
        $up->execute([
            ':empresa' => $idEmpresa, ':code' => $code,
            ':enabled' => !$restorePlan && BusinessConfigService::toBool($body['enabled'] ?? false) ? 'true' : 'false',
            ':activa' => !$restorePlan ? 'true' : 'false',
            ':motivo' => $motivo, ':actor' => $actorId ?: null, ':email' => $actorEmail ?: null,
        ]);
        $after = $this->applyEffectiveCapabilities($pdo, $idEmpresa);
        $this->audit(
            $pdo,
            $actorId,
            $actorEmail,
            $restorePlan ? 'SAAS_CAPACIDAD_EXCEPCION_RESTAURADA' : 'SAAS_CAPACIDAD_EXCEPCION_SAVE',
            $idEmpresa,
            $before,
            $after
        );
        return $after;
    }

    /** @return array<int,array<string,mixed>> */
    public function applyEffectiveCapabilities(PDO $pdo, int $idEmpresa): array
    {
        $effective = $this->companyCapabilities($pdo, $idEmpresa, false);
        $this->guardSupportDisable($pdo, $idEmpresa, $effective);
        $up = $pdo->prepare('
            INSERT INTO pos_saas.empresa_capacidad (id_empresa, codigo_capacidad, enabled)
            VALUES (:empresa, :code, CAST(:enabled AS boolean))
            ON CONFLICT (id_empresa, codigo_capacidad)
            DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
        ');
        foreach ($effective as $row) {
            $up->execute([
                ':empresa' => $idEmpresa,
                ':code' => (string)$row['codigo_capacidad'],
                ':enabled' => !empty($row['efectiva']) ? 'true' : 'false',
            ]);
        }
        return $effective;
    }

    public function assertModuleCanBeEnabled(PDO $pdo, int $idEmpresa, int $idModulo): void
    {
        $this->assertMappedResourceCanBeEnabled($pdo, $idEmpresa, 'admin.saas_capacidad_modulo', 'id_modulo', $idModulo);
    }

    public function assertPermissionCanBeEnabled(PDO $pdo, int $idEmpresa, int $idPermiso): void
    {
        $this->assertMappedResourceCanBeEnabled($pdo, $idEmpresa, 'admin.saas_capacidad_permiso', 'id_permiso', $idPermiso);
    }

    private function assertMappedResourceCanBeEnabled(PDO $pdo, int $idEmpresa, string $catalogTable, string $resourceColumn, int $resourceId): void
    {
        $available = $pdo->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
        $available->execute([':table_name' => $catalogTable]);
        if (!BusinessConfigService::toBool($available->fetchColumn())) {
            return;
        }

        $requirements = $pdo->prepare("SELECT codigo_capacidad FROM {$catalogTable} WHERE {$resourceColumn} = :resource ORDER BY codigo_capacidad");
        $requirements->execute([':resource' => $resourceId]);
        $codes = array_values(array_unique(array_map('strval', $requirements->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if ($codes === []) {
            return;
        }

        $effective = [];
        foreach ($this->companyCapabilities($pdo, $idEmpresa, false) as $row) {
            $effective[(string)$row['codigo_capacidad']] = !empty($row['efectiva']);
        }
        $missing = array_values(array_filter($codes, static fn (string $code): bool => empty($effective[$code])));
        if ($missing !== []) {
            throw new \InvalidArgumentException('CAPACIDAD_SAAS_REQUERIDA:' . implode(',', $missing));
        }
    }

    /** @param array<int,array<string,mixed>> $effective */
    private function guardSupportDisable(PDO $pdo, int $idEmpresa, array $effective): void
    {
        foreach ($effective as $row) {
            if (($row['codigo_capacidad'] ?? null) !== 'SOPORTE_TECNICO' || !empty($row['efectiva'])) {
                continue;
            }
            $diagnostic = $this->supportDiagnostic->diagnose($idEmpresa);
            $current = $pdo->prepare('
                SELECT enabled
                FROM pos_saas.empresa_capacidad
                WHERE id_empresa = :empresa AND codigo_capacidad = :codigo
                LIMIT 1
            ');
            $current->execute([':empresa' => $idEmpresa, ':codigo' => 'SOPORTE_TECNICO']);
            $wasEnabled = BusinessConfigService::toBool($current->fetchColumn())
                || !empty($diagnostic['capacidad_activa_tenant']);
            if (!$wasEnabled) {
                return;
            }
            if (!empty($diagnostic['bloquea_desactivacion'])) {
                throw new SaasCapabilityDisableBlockedException($diagnostic);
            }
            return;
        }
    }

    /** @return array<int,int> */
    public function applyPlanToSubscribers(PDO $pdo, int $idPlan): array
    {
        $st = $pdo->prepare('SELECT id_empresa FROM admin.saas_suscripcion WHERE id_plan = :plan');
        $st->execute([':plan' => $idPlan]);
        $companies = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($companies as $companyId) {
            $this->applyEffectiveCapabilities($pdo, $companyId);
        }
        return $companies;
    }

    /** @return array<string,bool> */
    private function normalizeMap(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('CAPACIDADES_INVALIDAS');
        }
        $out = array_fill_keys(self::MANAGED_CODES, false);
        foreach ($payload as $key => $value) {
            $code = is_array($value)
                ? strtoupper(trim((string)($value['codigo_capacidad'] ?? '')))
                : strtoupper(trim((string)$key));
            if (!$this->isManaged($code)) {
                throw new \InvalidArgumentException('CAPACIDAD_NO_GESTIONADA_POR_SAAS');
            }
            $out[$code] = is_array($value)
                ? BusinessConfigService::toBool($value['incluida'] ?? $value['enabled'] ?? false)
                : BusinessConfigService::toBool($value);
        }
        return $out;
    }

    /** @param array<string,bool> $capabilities */
    private function validateDependencies(array $capabilities): void
    {
        if (!empty($capabilities['EXPORTACION_CONTABLE']) && empty($capabilities['PRECONTABILIDAD'])) {
            throw new \InvalidArgumentException('EXPORTACION_CONTABLE_REQUIERE_PRECONTABILIDAD');
        }
        if (!empty($capabilities['NOTAS_FISCALES']) && empty($capabilities['FACTURACION_ELECTRONICA'])) {
            throw new \InvalidArgumentException('NOTAS_FISCALES_REQUIERE_FACTURACION_ELECTRONICA');
        }
    }

    private function audit(PDO $pdo, int $actorId, string $actorEmail, string $action, int $idEmpresa, array $before, array $after): void
    {
        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
            VALUES (:actor, :email, :action, :type, :target, :before::jsonb, :after::jsonb, :ip, :ua)
        ');
        $st->execute([
            ':actor' => $actorId ?: null, ':email' => $actorEmail ?: null,
            ':action' => $action, ':type' => 'empresa', ':target' => $idEmpresa,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /** @param array<int,array<string,mixed>> $before @param array<int,array<string,mixed>> $after */
    private function auditPlanCapabilityMatrix(PDO $pdo, int $actorId, string $actorEmail, int $idPlan, array $before, array $after): void
    {
        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
            VALUES (:actor, :email, :action, :type, :target, :before::jsonb, :after::jsonb, :ip, :ua)
        ');
        $st->execute([
            ':actor' => $actorId ?: null,
            ':email' => $actorEmail ?: null,
            ':action' => 'SAAS_PLAN_CAPACIDADES_SAVE',
            ':type' => 'saas_plan',
            ':target' => $idPlan,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }
}

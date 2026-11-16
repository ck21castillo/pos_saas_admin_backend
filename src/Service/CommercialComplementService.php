<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use DateTimeImmutable;
use PDO;

/** Commercial catalog and company activations. It never writes tenant data. */
final class CommercialComplementService
{
    public function hasStorage(PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
        $st->execute([':table_name' => 'admin.saas_complemento']);
        return $this->bool($st->fetchColumn());
    }

    public function plansMode(): bool
    {
        // The migration is safe to deploy before switching the old UI off.
        // Keep the same opt-in transition contract as POS: only an explicit
        // `plans` enables the new commercial-only write boundary.
        return strtolower(trim((string)($_ENV['ENTITLEMENTS_MODE'] ?? 'legacy'))) === 'plans';
    }

    /** @return list<array<string,mixed>> */
    public function catalog(PDO $pdo, bool $includeInternal = true): array
    {
        $this->assertStorage($pdo);
        $where = $includeInternal ? '' : "WHERE c.tipo_comercial = 'COMPLEMENTO'";
        $rows = $pdo->query('
            SELECT c.id_complemento, c.codigo, c.nombre, c.descripcion, c.activo,
                   c.tipo_comercial, c.valor_mensual, c.valor_anual, c.created_at, c.updated_at,
                   COALESCE(array_agg(DISTINCT p.codigo) FILTER (WHERE p.codigo IS NOT NULL), ARRAY[]::text[]) AS planes_compatibles
            FROM admin.saas_complemento c
            LEFT JOIN admin.saas_complemento_plan cp ON cp.id_complemento = c.id_complemento
            LEFT JOIN admin.saas_plan p ON p.id_plan = cp.id_plan
            ' . $where . '
            GROUP BY c.id_complemento
            ORDER BY c.tipo_comercial ASC, c.codigo ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalizeCatalogItem'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function eligibleForCompany(PDO $pdo, int $idEmpresa): array
    {
        $this->assertStorage($pdo);
        $plan = $this->companyPlan($pdo, $idEmpresa);
        if ($plan === null) {
            throw new \RuntimeException('SUSCRIPCION_NO_ENCONTRADA');
        }

        $st = $pdo->prepare('
            SELECT c.id_complemento, c.codigo, c.nombre, c.descripcion, c.activo,
                   c.tipo_comercial, c.valor_mensual, c.valor_anual,
                   ec.estado AS activacion_estado, ec.fecha_inicio, ec.fecha_fin,
                   EXISTS (
                       SELECT 1 FROM admin.saas_complemento_plan cp
                       WHERE cp.id_complemento = c.id_complemento AND cp.id_plan = :plan
                   ) AS compatible,
                   NOT EXISTS (
                       SELECT 1
                       FROM admin.saas_complemento_dependencia d
                       LEFT JOIN admin.empresa_saas_complemento dependency
                         ON dependency.id_empresa = :empresa
                        AND dependency.id_complemento = d.id_complemento_requerido
                        AND dependency.estado = \'ACTIVO\'
                        AND dependency.fecha_inicio <= current_date
                        AND (dependency.fecha_fin IS NULL OR dependency.fecha_fin >= current_date)
                       WHERE d.id_complemento = c.id_complemento
                         AND dependency.id_empresa IS NULL
                   ) AS dependencias_cumplidas
            FROM admin.saas_complemento c
            LEFT JOIN admin.empresa_saas_complemento ec
              ON ec.id_empresa = :empresa AND ec.id_complemento = c.id_complemento
            WHERE c.tipo_comercial = \'COMPLEMENTO\'
            ORDER BY c.codigo ASC
        ');
        $st->execute([':empresa' => $idEmpresa, ':plan' => $plan['id_plan']]);

        return array_map(function (array $row) use ($pdo, $idEmpresa, $plan): array {
            $item = $this->normalizeCatalogItem($row);
            $item['plan_actual'] = $plan['codigo'];
            $item['compatible'] = $this->bool($row['compatible'] ?? false);
            $item['dependencias_cumplidas'] = $this->bool($row['dependencias_cumplidas'] ?? false);
            $item['estado_activacion'] = $row['activacion_estado'] ?? null;
            return array_merge($item, $this->eligibilityForComplement(
                $pdo,
                $idEmpresa,
                (int)$plan['id_plan'],
                (int)$row['id_complemento'],
                $item['activo'],
                $item['compatible'],
                $item['dependencias_cumplidas']
            ));
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function companyActivations(PDO $pdo, int $idEmpresa): array
    {
        $this->assertStorage($pdo);
        $plan = $this->companyPlan($pdo, $idEmpresa);
        $planId = (int)($plan['id_plan'] ?? 0);
        $st = $pdo->prepare('
            SELECT ec.*, c.codigo, c.nombre, c.descripcion, c.activo, c.tipo_comercial,
                   EXISTS (
                     SELECT 1 FROM admin.saas_complemento_plan cp
                     WHERE cp.id_complemento = c.id_complemento AND cp.id_plan = :plan
                   ) AS compatible,
                   sa.email AS actor_email
            FROM admin.empresa_saas_complemento ec
            JOIN admin.saas_complemento c ON c.id_complemento = ec.id_complemento
            LEFT JOIN admin.superadmin_user sa ON sa.id_superadmin = ec.actor_id
            WHERE ec.id_empresa = :empresa
            ORDER BY c.codigo ASC
        ');
        $st->execute([':empresa' => $idEmpresa, ':plan' => $planId]);
        return array_map([$this, 'normalizeActivation'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function activate(PDO $pdo, int $idEmpresa, string $code, array $body, int $actorId, string $actorEmail): array
    {
        $this->assertStorage($pdo);
        $this->assertCompany($pdo, $idEmpresa);
        $this->assertActor($actorId);
        $complement = $this->complementByCode($pdo, $code);
        if (!$this->bool($complement['activo'])) {
            throw new \InvalidArgumentException('COMPLEMENTO_INACTIVO');
        }
        if ((string)$complement['tipo_comercial'] !== 'COMPLEMENTO') {
            throw new \InvalidArgumentException('COMPLEMENTO_SOLO_INTERNO');
        }
        $plan = $this->companyPlan($pdo, $idEmpresa);
        if ($plan === null || !$this->isCompatibleWithPlan($pdo, (int)$complement['id_complemento'], (int)$plan['id_plan'])) {
            throw new \InvalidArgumentException('COMPLEMENTO_INCOMPATIBLE_CON_PLAN');
        }
        $this->assertDependencies($pdo, $idEmpresa, (int)$complement['id_complemento']);
        $eligibility = $this->eligibilityForComplement(
            $pdo,
            $idEmpresa,
            (int)$plan['id_plan'],
            (int)$complement['id_complemento'],
            true,
            true,
            true
        );
        if (!empty($eligibility['incluido_en_plan'])) {
            throw new \InvalidArgumentException('COMPLEMENTO_YA_INCLUIDO_EN_PLAN');
        }
        if (!empty($eligibility['capacidades_requeridas_faltantes'])) {
            throw new \InvalidArgumentException(
                'COMPLEMENTO_REQUIERE_CAPACIDAD:' . implode(',', $eligibility['capacidades_requeridas_faltantes'])
            );
        }
        if (empty($eligibility['elegible'])) {
            throw new \InvalidArgumentException('COMPLEMENTO_NO_ELEGIBLE');
        }

        $before = $this->activationByComplement($pdo, $idEmpresa, (int)$complement['id_complemento']);
        $ciclo = strtoupper(trim((string)($body['ciclo_cobro'] ?? 'MENSUAL')));
        if (!in_array($ciclo, ['MENSUAL', 'ANUAL'], true)) {
            throw new \InvalidArgumentException('COMPLEMENTO_CICLO_INVALIDO');
        }
        $inicio = $this->date($body['fecha_inicio'] ?? null, (new DateTimeImmutable('today'))->format('Y-m-d'));
        $fin = $this->nullableDate($body['fecha_fin'] ?? null);
        if ($fin !== null && $fin < $inicio) {
            throw new \InvalidArgumentException('COMPLEMENTO_FECHAS_INVALIDAS');
        }
        $mensual = $this->money($body['valor_mensual_acordado'] ?? $complement['valor_mensual']);
        $anual = $this->money($body['valor_anual_acordado'] ?? $complement['valor_anual']);
        $observacion = $this->nullableText($body['observacion'] ?? null, 1000);

        $up = $pdo->prepare('
            INSERT INTO admin.empresa_saas_complemento (
                id_empresa, id_complemento, estado, valor_mensual_acordado, valor_anual_acordado,
                ciclo_cobro, fecha_inicio, fecha_fin, medio_pago, referencia_pago, observacion, actor_id
            ) VALUES (
                :empresa, :complemento, \'ACTIVO\', :mensual, :anual,
                :ciclo, :inicio, :fin, :medio, :referencia, :observacion, :actor
            )
            ON CONFLICT (id_empresa, id_complemento) DO UPDATE SET
                estado = \'ACTIVO\', valor_mensual_acordado = EXCLUDED.valor_mensual_acordado,
                valor_anual_acordado = EXCLUDED.valor_anual_acordado, ciclo_cobro = EXCLUDED.ciclo_cobro,
                fecha_inicio = EXCLUDED.fecha_inicio, fecha_fin = EXCLUDED.fecha_fin,
                medio_pago = EXCLUDED.medio_pago, referencia_pago = EXCLUDED.referencia_pago,
                observacion = EXCLUDED.observacion, actor_id = EXCLUDED.actor_id, updated_at = now()
        ');
        $up->execute([
            ':empresa' => $idEmpresa, ':complemento' => $complement['id_complemento'],
            ':mensual' => $mensual, ':anual' => $anual, ':ciclo' => $ciclo,
            ':inicio' => $inicio, ':fin' => $fin,
            ':medio' => $this->nullableText($body['medio_pago'] ?? null, 80),
            ':referencia' => $this->nullableText($body['referencia_pago'] ?? null, 160),
            ':observacion' => $observacion, ':actor' => $actorId,
        ]);
        $after = $this->activationByComplement($pdo, $idEmpresa, (int)$complement['id_complemento']);
        $this->audit($pdo, $actorId, $actorEmail, 'EMPRESA_COMPLEMENTO_ACTIVAR', $idEmpresa, $before, $after);
        return $after ?? throw new \RuntimeException('COMPLEMENTO_ACTIVACION_NO_ENCONTRADA');
    }

    /** @return array<string,mixed> */
    public function deactivate(PDO $pdo, int $idEmpresa, string $code, string $observacion, int $actorId, string $actorEmail): array
    {
        return $this->setActivationState($pdo, $idEmpresa, $code, 'INACTIVO', $observacion, $actorId, $actorEmail);
    }

    /** @return array<string,mixed> */
    /** @param array<string,mixed> $body */
    public function restore(PDO $pdo, int $idEmpresa, string $code, array $body, int $actorId, string $actorEmail): array
    {
        $activation = $this->activationByCode($pdo, $idEmpresa, $code);
        if ($activation === null) {
            throw new \RuntimeException('COMPLEMENTO_ACTIVACION_NO_ENCONTRADA');
        }
        return $this->activate($pdo, $idEmpresa, $code, [
            'ciclo_cobro' => $activation['ciclo_cobro'],
            'fecha_inicio' => $body['fecha_inicio'] ?? (new DateTimeImmutable('today'))->format('Y-m-d'),
            'fecha_fin' => $body['fecha_fin'] ?? null,
            'valor_mensual_acordado' => $activation['valor_mensual_acordado'],
            'valor_anual_acordado' => $activation['valor_anual_acordado'],
            'medio_pago' => $activation['medio_pago'],
            'referencia_pago' => $activation['referencia_pago'],
            'observacion' => $body['observacion'] ?? null,
        ], $actorId, $actorEmail);
    }

    /** @return array{modulos:list<int>,permisos:list<int>,capacidades:list<string>} */
    public function activeResources(PDO $pdo, int $idEmpresa, int $idPlan): array
    {
        $this->assertStorage($pdo);
        return [
            'modulos' => $this->activeMappedIds($pdo, $idEmpresa, $idPlan, 'admin.saas_complemento_modulo', 'id_modulo'),
            'permisos' => $this->activeMappedIds($pdo, $idEmpresa, $idPlan, 'admin.saas_complemento_permiso', 'id_permiso'),
            'capacidades' => $this->activeMappedCodes($pdo, $idEmpresa, $idPlan),
        ];
    }

    public function isInternalModule(PDO $pdo, int $idModulo): bool
    {
        return $this->isInternalResource($pdo, 'admin.saas_complemento_modulo', 'id_modulo', $idModulo);
    }

    public function isInternalPermission(PDO $pdo, int $idPermiso): bool
    {
        return $this->isInternalResource($pdo, 'admin.saas_complemento_permiso', 'id_permiso', $idPermiso);
    }

    /** @return array{modulos:int,permisos:int,capacidades:int} */
    public function applyInternalException(PDO $pdo, int $idEmpresa, string $code, bool $enabled, string $motivo, int $actorId, string $actorEmail): array
    {
        $this->assertStorage($pdo);
        $this->assertCompany($pdo, $idEmpresa);
        $this->assertActor($actorId);
        if (mb_strlen(trim($motivo)) < 10) {
            throw new \InvalidArgumentException('EXCEPCION_MOTIVO_MINIMO_10');
        }
        $complement = $this->complementByCode($pdo, $code);
        if ((string)$complement['tipo_comercial'] !== 'SOLO_INTERNO') {
            throw new \InvalidArgumentException('EXCEPCION_INTERNA_CATALOGO_REQUERIDO');
        }

        $idComplemento = (int)$complement['id_complemento'];
        $modules = $pdo->prepare('SELECT id_modulo FROM admin.saas_complemento_modulo WHERE id_complemento = :id');
        $modules->execute([':id' => $idComplemento]);
        $permissions = $pdo->prepare('SELECT id_permiso FROM admin.saas_complemento_permiso WHERE id_complemento = :id');
        $permissions->execute([':id' => $idComplemento]);
        $capabilities = $pdo->prepare('SELECT codigo_capacidad FROM admin.saas_complemento_capacidad WHERE id_complemento = :id');
        $capabilities->execute([':id' => $idComplemento]);

        $moduleIds = array_map('intval', $modules->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $permissionIds = array_map('intval', $permissions->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $capabilityCodes = array_map('strval', $capabilities->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($moduleIds === [] && $permissionIds === [] && $capabilityCodes === []) {
            throw new \RuntimeException('EXCEPCION_INTERNA_SIN_RECURSOS');
        }

        $moduleUpsert = $pdo->prepare('
            INSERT INTO admin.empresa_entitlement_excepcion (id_empresa, id_modulo, enabled, motivo, actor_id)
            VALUES (:empresa, :recurso, CAST(:enabled AS boolean), :motivo, :actor)
            ON CONFLICT (id_empresa, id_modulo) WHERE id_modulo IS NOT NULL DO UPDATE
            SET enabled = EXCLUDED.enabled, motivo = EXCLUDED.motivo, actor_id = EXCLUDED.actor_id, updated_at = now()
        ');
        foreach ($moduleIds as $idModulo) {
            $moduleUpsert->execute([':empresa' => $idEmpresa, ':recurso' => $idModulo, ':enabled' => $enabled ? 'true' : 'false', ':motivo' => $motivo, ':actor' => $actorId]);
        }
        $permissionUpsert = $pdo->prepare('
            INSERT INTO admin.empresa_entitlement_excepcion (id_empresa, id_permiso, enabled, motivo, actor_id)
            VALUES (:empresa, :recurso, CAST(:enabled AS boolean), :motivo, :actor)
            ON CONFLICT (id_empresa, id_permiso) WHERE id_permiso IS NOT NULL DO UPDATE
            SET enabled = EXCLUDED.enabled, motivo = EXCLUDED.motivo, actor_id = EXCLUDED.actor_id, updated_at = now()
        ');
        foreach ($permissionIds as $idPermiso) {
            $permissionUpsert->execute([':empresa' => $idEmpresa, ':recurso' => $idPermiso, ':enabled' => $enabled ? 'true' : 'false', ':motivo' => $motivo, ':actor' => $actorId]);
        }
        $capabilityUpsert = $pdo->prepare('
            INSERT INTO admin.saas_empresa_capacidad_excepcion
                (id_empresa, codigo_capacidad, enabled, motivo, activa, creado_por, creado_por_email)
            VALUES (:empresa, :codigo, CAST(:enabled AS boolean), :motivo, true, :actor, :email)
            ON CONFLICT (id_empresa, codigo_capacidad) DO UPDATE
            SET enabled = EXCLUDED.enabled, motivo = EXCLUDED.motivo, activa = true,
                creado_por = EXCLUDED.creado_por, creado_por_email = EXCLUDED.creado_por_email, updated_at = now()
        ');
        foreach ($capabilityCodes as $capabilityCode) {
            $capabilityUpsert->execute([
                ':empresa' => $idEmpresa, ':codigo' => $capabilityCode,
                ':enabled' => $enabled ? 'true' : 'false', ':motivo' => $motivo,
                ':actor' => $actorId, ':email' => $actorEmail,
            ]);
        }

        $this->audit($pdo, $actorId, $actorEmail, 'EMPRESA_EXCEPCION_INTERNA_' . ($enabled ? 'HABILITAR' : 'DESACTIVAR'), $idEmpresa, null, [
            'codigo' => $complement['codigo'], 'enabled' => $enabled,
            'modulos' => $moduleIds, 'permisos' => $permissionIds, 'capacidades' => $capabilityCodes,
        ]);
        return ['modulos' => count($moduleIds), 'permisos' => count($permissionIds), 'capacidades' => count($capabilityCodes)];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateCatalog(PDO $pdo, string $code, array $body, int $actorId, string $actorEmail): array
    {
        $this->assertStorage($pdo);
        $this->assertActor($actorId);
        $before = $this->complementByCode($pdo, $code);
        $up = $pdo->prepare('
            UPDATE admin.saas_complemento
            SET nombre = :nombre, descripcion = :descripcion, activo = CAST(:activo AS boolean),
                valor_mensual = :mensual, valor_anual = :anual, updated_at = now()
            WHERE id_complemento = :id
        ');
        $up->execute([
            ':id' => $before['id_complemento'],
            ':nombre' => $this->requiredText($body['nombre'] ?? $before['nombre'], 160),
            ':descripcion' => $this->nullableText($body['descripcion'] ?? $before['descripcion'], 1000),
            ':activo' => $this->bool($body['activo'] ?? $before['activo']) ? 'true' : 'false',
            ':mensual' => $this->money($body['valor_mensual'] ?? $before['valor_mensual']),
            ':anual' => $this->money($body['valor_anual'] ?? $before['valor_anual']),
        ]);
        $after = $this->complementByCode($pdo, $code);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_COMPLEMENTO_UPDATE', 0, $before, $after);
        return $this->normalizeCatalogItem($after);
    }

    /**
     * Determines commercial eligibility from canonical resource maps, never
     * from technical access mirrors.
     *
     * @return array{elegible:bool,estado_comercial:string,incluido_en_plan:bool,recursos:array<string,array{total:int,incluidos_en_plan:int,adicionales:int}>,capacidades_requeridas_faltantes:list<string>}
     */
    private function eligibilityForComplement(
        PDO $pdo,
        int $idEmpresa,
        int $idPlan,
        int $idComplemento,
        bool $catalogActive,
        bool $compatible,
        bool $dependenciesMet
    ): array {
        $resources = $this->complementResources($pdo, $idComplemento);
        $planResources = $this->planResources($pdo, $idPlan);
        $summary = [];
        $allIncluded = true;
        $hasResource = false;
        foreach (['modulos', 'permisos', 'capacidades'] as $kind) {
            $mapped = $resources[$kind];
            $included = array_values(array_intersect($mapped, $planResources[$kind]));
            $total = count($mapped);
            $includedCount = count($included);
            $hasResource = $hasResource || $total > 0;
            $allIncluded = $allIncluded && $total === $includedCount;
            $summary[$kind] = [
                'total' => $total,
                'incluidos_en_plan' => $includedCount,
                'adicionales' => $total - $includedCount,
            ];
        }

        $requiredCapabilities = array_values(array_diff(
            $this->resourceCapabilityRequirements($pdo, $idComplemento),
            $resources['capacidades']
        ));
        $effectiveCapabilities = $this->effectiveCapabilityCodes($pdo, $idEmpresa);
        $missingCapabilities = array_values(array_diff($requiredCapabilities, $effectiveCapabilities));
        sort($missingCapabilities);
        $includedInPlan = $hasResource && $allIncluded;

        $state = 'ACTIVABLE';
        if (!$catalogActive) {
            $state = 'CATALOGO_INACTIVO';
        } elseif (!$compatible) {
            $state = 'INCOMPATIBLE_PLAN';
        } elseif (!$dependenciesMet) {
            $state = 'REQUIERE_DEPENDENCIA';
        } elseif ($missingCapabilities !== []) {
            $state = 'BLOQUEADO_CAPACIDAD';
        } elseif ($includedInPlan) {
            $state = 'INCLUIDO_EN_PLAN';
        } elseif (!$hasResource) {
            $state = 'SIN_RECURSOS';
        }

        return [
            'elegible' => $state === 'ACTIVABLE',
            'estado_comercial' => $state,
            'incluido_en_plan' => $includedInPlan,
            'recursos' => $summary,
            'capacidades_requeridas_faltantes' => $missingCapabilities,
        ];
    }

    /** @return array{modulos:list<int>,permisos:list<int>,capacidades:list<string>} */
    private function complementResources(PDO $pdo, int $idComplemento): array
    {
        $modules = $pdo->prepare('SELECT id_modulo FROM admin.saas_complemento_modulo WHERE id_complemento = :id');
        $permissions = $pdo->prepare('SELECT id_permiso FROM admin.saas_complemento_permiso WHERE id_complemento = :id');
        $capabilities = $pdo->prepare('SELECT codigo_capacidad FROM admin.saas_complemento_capacidad WHERE id_complemento = :id');
        foreach ([$modules, $permissions, $capabilities] as $statement) {
            $statement->execute([':id' => $idComplemento]);
        }
        return [
            'modulos' => array_map('intval', $modules->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'permisos' => array_map('intval', $permissions->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'capacidades' => array_map('strval', $capabilities->fetchAll(PDO::FETCH_COLUMN) ?: []),
        ];
    }

    /** @return array{modulos:list<int>,permisos:list<int>,capacidades:list<string>} */
    private function planResources(PDO $pdo, int $idPlan): array
    {
        $modules = $pdo->prepare('SELECT id_modulo FROM admin.saas_plan_modulo WHERE id_plan = :plan AND enabled = true');
        $permissions = $pdo->prepare('SELECT id_permiso FROM admin.saas_plan_permiso WHERE id_plan = :plan AND enabled = true');
        $capabilities = $pdo->prepare('SELECT codigo_capacidad FROM admin.saas_plan_capacidad WHERE id_plan = :plan AND incluida = true');
        foreach ([$modules, $permissions, $capabilities] as $statement) {
            $statement->execute([':plan' => $idPlan]);
        }
        return [
            'modulos' => array_map('intval', $modules->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'permisos' => array_map('intval', $permissions->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'capacidades' => array_map('strval', $capabilities->fetchAll(PDO::FETCH_COLUMN) ?: []),
        ];
    }

    /** @return list<string> */
    private function resourceCapabilityRequirements(PDO $pdo, int $idComplemento): array
    {
        if (!$this->tableExists($pdo, 'admin.saas_capacidad_modulo') || !$this->tableExists($pdo, 'admin.saas_capacidad_permiso')) {
            return [];
        }
        $st = $pdo->prepare('
            SELECT DISTINCT required.codigo_capacidad
            FROM (
                SELECT cm.codigo_capacidad
                FROM admin.saas_complemento_modulo resource
                JOIN admin.saas_capacidad_modulo cm ON cm.id_modulo = resource.id_modulo
                WHERE resource.id_complemento = :id
                UNION
                SELECT cp.codigo_capacidad
                FROM admin.saas_complemento_permiso resource
                JOIN admin.saas_capacidad_permiso cp ON cp.id_permiso = resource.id_permiso
                WHERE resource.id_complemento = :id
            ) required
            ORDER BY required.codigo_capacidad
        ');
        $st->execute([':id' => $idComplemento]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<string> */
    private function effectiveCapabilityCodes(PDO $pdo, int $idEmpresa): array
    {
        $effective = [];
        foreach ((new SaasCapabilityService())->companyCapabilities($pdo, $idEmpresa, false) as $capability) {
            if (!empty($capability['efectiva'])) {
                $effective[] = (string)$capability['codigo_capacidad'];
            }
        }
        return array_values(array_unique($effective));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
        $st->execute([':table_name' => $table]);
        return $this->bool($st->fetchColumn());
    }

    /** @return list<int> */
    private function activeMappedIds(PDO $pdo, int $idEmpresa, int $idPlan, string $table, string $column): array
    {
        $st = $pdo->prepare("\n            SELECT DISTINCT r.{$column}\n            FROM admin.empresa_saas_complemento ec\n            JOIN admin.saas_complemento c ON c.id_complemento = ec.id_complemento\n            JOIN admin.saas_complemento_plan cp ON cp.id_complemento = c.id_complemento AND cp.id_plan = :plan\n            JOIN {$table} r ON r.id_complemento = c.id_complemento\n            WHERE ec.id_empresa = :empresa\n              AND ec.estado = 'ACTIVO'\n              AND ec.fecha_inicio <= current_date\n              AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= current_date)\n              AND c.activo = true\n              AND c.tipo_comercial = 'COMPLEMENTO'\n        ");
        $st->execute([':empresa' => $idEmpresa, ':plan' => $idPlan]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<string> */
    private function activeMappedCodes(PDO $pdo, int $idEmpresa, int $idPlan): array
    {
        $st = $pdo->prepare("\n            SELECT DISTINCT r.codigo_capacidad\n            FROM admin.empresa_saas_complemento ec\n            JOIN admin.saas_complemento c ON c.id_complemento = ec.id_complemento\n            JOIN admin.saas_complemento_plan cp ON cp.id_complemento = c.id_complemento AND cp.id_plan = :plan\n            JOIN admin.saas_complemento_capacidad r ON r.id_complemento = c.id_complemento\n            WHERE ec.id_empresa = :empresa\n              AND ec.estado = 'ACTIVO'\n              AND ec.fecha_inicio <= current_date\n              AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= current_date)\n              AND c.activo = true\n              AND c.tipo_comercial = 'COMPLEMENTO'\n        ");
        $st->execute([':empresa' => $idEmpresa, ':plan' => $idPlan]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function isInternalResource(PDO $pdo, string $table, string $column, int $id): bool
    {
        $this->assertStorage($pdo);
        $st = $pdo->prepare("\n            SELECT 1\n            FROM {$table} r\n            JOIN admin.saas_complemento c ON c.id_complemento = r.id_complemento\n            WHERE r.{$column} = :id AND c.tipo_comercial = 'SOLO_INTERNO' AND c.activo = true\n            LIMIT 1\n        ");
        $st->execute([':id' => $id]);
        return (bool)$st->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function setActivationState(PDO $pdo, int $idEmpresa, string $code, string $state, string $observacion, int $actorId, string $actorEmail): array
    {
        $this->assertStorage($pdo);
        $this->assertActor($actorId);
        $complement = $this->complementByCode($pdo, $code);
        if ((string)$complement['tipo_comercial'] !== 'COMPLEMENTO') {
            throw new \InvalidArgumentException('COMPLEMENTO_SOLO_INTERNO');
        }
        $before = $this->activationByComplement($pdo, $idEmpresa, (int)$complement['id_complemento']);
        if ($before === null) {
            throw new \RuntimeException('COMPLEMENTO_ACTIVACION_NO_ENCONTRADA');
        }
        $st = $pdo->prepare('
            UPDATE admin.empresa_saas_complemento
            SET estado = :estado, observacion = :observacion, actor_id = :actor, updated_at = now()
            WHERE id_empresa = :empresa AND id_complemento = :complemento
        ');
        $st->execute([
            ':estado' => $state, ':observacion' => $this->nullableText($observacion, 1000), ':actor' => $actorId,
            ':empresa' => $idEmpresa, ':complemento' => $complement['id_complemento'],
        ]);
        $after = $this->activationByComplement($pdo, $idEmpresa, (int)$complement['id_complemento']);
        $this->audit($pdo, $actorId, $actorEmail, 'EMPRESA_COMPLEMENTO_' . $state, $idEmpresa, $before, $after);
        return $after ?? throw new \RuntimeException('COMPLEMENTO_ACTIVACION_NO_ENCONTRADA');
    }

    /** @return array<string,mixed>|null */
    private function companyPlan(PDO $pdo, int $idEmpresa): ?array
    {
        $st = $pdo->prepare('
            SELECT s.id_plan, p.codigo, p.nombre
            FROM admin.saas_suscripcion s
            JOIN admin.saas_plan p ON p.id_plan = s.id_plan
            WHERE s.id_empresa = :empresa
              AND s.estado IN (\'PRUEBA\', \'ACTIVA\', \'POR_VENCER\', \'VENCIDA\')
            ORDER BY s.updated_at DESC, s.id_suscripcion DESC
            LIMIT 1
        ');
        $st->execute([':empresa' => $idEmpresa]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['id_plan'] = (int)$row['id_plan'];
        return $row;
    }

    /** @return array<string,mixed> */
    private function complementByCode(PDO $pdo, string $code): array
    {
        $code = strtoupper(trim($code));
        $st = $pdo->prepare('SELECT * FROM admin.saas_complemento WHERE codigo = :codigo LIMIT 1');
        $st->execute([':codigo' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \RuntimeException('COMPLEMENTO_NO_ENCONTRADO');
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function activationByCode(PDO $pdo, int $idEmpresa, string $code): ?array
    {
        $complement = $this->complementByCode($pdo, $code);
        return $this->activationByComplement($pdo, $idEmpresa, (int)$complement['id_complemento']);
    }

    /** @return array<string,mixed>|null */
    private function activationByComplement(PDO $pdo, int $idEmpresa, int $idComplemento): ?array
    {
        $st = $pdo->prepare('
            SELECT ec.*, c.codigo, c.nombre, c.descripcion, c.activo, c.tipo_comercial,
                   true AS compatible, sa.email AS actor_email
            FROM admin.empresa_saas_complemento ec
            JOIN admin.saas_complemento c ON c.id_complemento = ec.id_complemento
            LEFT JOIN admin.superadmin_user sa ON sa.id_superadmin = ec.actor_id
            WHERE ec.id_empresa = :empresa AND ec.id_complemento = :complemento
            LIMIT 1
        ');
        $st->execute([':empresa' => $idEmpresa, ':complemento' => $idComplemento]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeActivation($row) : null;
    }

    private function isCompatibleWithPlan(PDO $pdo, int $idComplemento, int $idPlan): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM admin.saas_complemento_plan WHERE id_complemento = :complemento AND id_plan = :plan');
        $st->execute([':complemento' => $idComplemento, ':plan' => $idPlan]);
        return (bool)$st->fetchColumn();
    }

    private function assertDependencies(PDO $pdo, int $idEmpresa, int $idComplemento): void
    {
        $st = $pdo->prepare('
            SELECT c.codigo
            FROM admin.saas_complemento_dependencia d
            JOIN admin.saas_complemento c ON c.id_complemento = d.id_complemento_requerido
            LEFT JOIN admin.empresa_saas_complemento ec
              ON ec.id_empresa = :empresa AND ec.id_complemento = c.id_complemento
             AND ec.estado = \'ACTIVO\' AND ec.fecha_inicio <= current_date
             AND (ec.fecha_fin IS NULL OR ec.fecha_fin >= current_date)
            WHERE d.id_complemento = :complemento AND ec.id_empresa IS NULL
        ');
        $st->execute([':empresa' => $idEmpresa, ':complemento' => $idComplemento]);
        $missing = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($missing !== []) {
            throw new \InvalidArgumentException('COMPLEMENTO_DEPENDENCIA_REQUERIDA:' . implode(',', $missing));
        }
    }

    private function assertStorage(PDO $pdo): void
    {
        if (!$this->hasStorage($pdo)) throw new \RuntimeException('COMPLEMENTOS_SCHEMA_MISSING');
    }

    private function assertCompany(PDO $pdo, int $idEmpresa): void
    {
        $st = $pdo->prepare('SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :empresa');
        $st->execute([':empresa' => $idEmpresa]);
        if (!$st->fetchColumn()) throw new \RuntimeException('EMPRESA_NO_ENCONTRADA');
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) throw new \RuntimeException('ACTOR_ADMIN_REQUERIDO');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCatalogItem(array $row): array
    {
        return [
            'id_complemento' => (int)$row['id_complemento'], 'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'], 'descripcion' => $row['descripcion'] ?? null,
            'activo' => $this->bool($row['activo'] ?? false), 'tipo_comercial' => (string)$row['tipo_comercial'],
            'valor_mensual' => (float)$row['valor_mensual'], 'valor_anual' => (float)$row['valor_anual'],
            'planes_compatibles' => $row['planes_compatibles'] ?? [],
            'created_at' => $row['created_at'] ?? null, 'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeActivation(array $row): array
    {
        return [
            'id_empresa_saas_complemento' => (int)$row['id_empresa_saas_complemento'],
            'codigo' => (string)$row['codigo'], 'nombre' => (string)$row['nombre'],
            'tipo_comercial' => (string)$row['tipo_comercial'], 'estado' => (string)$row['estado'],
            'compatible' => $this->bool($row['compatible'] ?? false),
            'efectiva' => (string)$row['estado'] === 'ACTIVO' && $this->bool($row['compatible'] ?? false)
                && (string)$row['fecha_inicio'] <= (new DateTimeImmutable('today'))->format('Y-m-d')
                && ($row['fecha_fin'] === null || (string)$row['fecha_fin'] >= (new DateTimeImmutable('today'))->format('Y-m-d')),
            'valor_mensual_acordado' => (float)$row['valor_mensual_acordado'],
            'valor_anual_acordado' => (float)$row['valor_anual_acordado'], 'ciclo_cobro' => (string)$row['ciclo_cobro'],
            'fecha_inicio' => $row['fecha_inicio'], 'fecha_fin' => $row['fecha_fin'],
            'medio_pago' => $row['medio_pago'], 'referencia_pago' => $row['referencia_pago'],
            'observacion' => $row['observacion'],
            'actor' => ['id_superadmin' => $row['actor_id'] === null ? null : (int)$row['actor_id'], 'email' => $row['actor_email'] ?? null],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    private function audit(PDO $pdo, int $actorId, string $actorEmail, string $action, int $idEmpresa, mixed $before, mixed $after): void
    {
        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
            VALUES (:actor, :email, :action, :type, :target, :before::jsonb, :after::jsonb, :ip, :ua)
        ');
        $st->execute([
            ':actor' => $actorId, ':email' => $actorEmail, ':action' => $action,
            ':type' => $idEmpresa > 0 ? 'empresa' : 'saas_complemento', ':target' => $idEmpresa ?: null,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function money(mixed $value): float
    {
        if (!is_numeric($value) || (float)$value < 0) throw new \InvalidArgumentException('COMPLEMENTO_VALOR_INVALIDO');
        return round((float)$value, 2);
    }

    private function date(mixed $value, string $default): string
    {
        $text = trim((string)$value);
        if ($text === '') return $default;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new \InvalidArgumentException('COMPLEMENTO_FECHA_INVALIDA');
        return $text;
    }

    private function nullableDate(mixed $value): ?string
    {
        return trim((string)$value) === '' ? null : $this->date($value, '');
    }

    private function requiredText(mixed $value, int $limit): string
    {
        $text = trim((string)$value);
        if ($text === '' || mb_strlen($text) > $limit) throw new \InvalidArgumentException('COMPLEMENTO_TEXTO_INVALIDO');
        return $text;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        if (mb_strlen($text) > $limit) throw new \InvalidArgumentException('COMPLEMENTO_TEXTO_INVALIDO');
        return $text;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }
}

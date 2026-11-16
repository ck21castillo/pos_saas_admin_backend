<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

/**
 * Reconciles only the technical capability mirror with the canonical SaaS
 * calculation. It deliberately never changes subscriptions, entitlements,
 * complements or exceptions.
 */
final class SaasCapabilityReconciliationService
{
    public function __construct(private readonly SaasCapabilityService $capabilities = new SaasCapabilityService())
    {
    }

    /** @return list<array{id_empresa:int,id_plan:int,plan_codigo:string}> */
    public function commercialCompanies(PDO $pdo): array
    {
        $states = SaasCapabilityService::effectiveSubscriptionStates();
        $params = [];
        $placeholders = [];
        foreach ($states as $index => $state) {
            $key = ':state_' . $index;
            $placeholders[] = $key;
            $params[$key] = $state;
        }
        $st = $pdo->prepare('
            SELECT DISTINCT ON (s.id_empresa)
                   s.id_empresa, s.id_plan, p.codigo AS plan_codigo
            FROM admin.saas_suscripcion s
            JOIN admin.saas_plan p ON p.id_plan = s.id_plan
            WHERE s.estado IN (' . implode(', ', $placeholders) . ')
            ORDER BY s.id_empresa, s.updated_at DESC, s.id_suscripcion DESC
        ');
        $st->execute($params);
        return array_map(static fn(array $row): array => [
            'id_empresa' => (int)$row['id_empresa'],
            'id_plan' => (int)$row['id_plan'],
            'plan_codigo' => (string)$row['plan_codigo'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array{codigo_capacidad:string,actual:?bool,esperado:bool}> */
    public function preview(PDO $pdo, int $idEmpresa): array
    {
        $expected = [];
        foreach ($this->capabilities->companyCapabilities($pdo, $idEmpresa, false) as $row) {
            $expected[(string)$row['codigo_capacidad']] = !empty($row['efectiva']);
        }
        $current = $this->materializedCapabilities($pdo, $idEmpresa);
        return self::diff($current, $expected);
    }

    /**
     * Applies the canonical materializer only when preview found a difference.
     * The caller owns the transaction and tenant outbox lifecycle.
     *
     * @return array{cambios:list<array{codigo_capacidad:string,actual:?bool,esperado:bool},capacidades_efectivas:list<array<string,mixed>>}
     */
    public function apply(PDO $pdo, int $idEmpresa, string $actorEmail = 'system'): array
    {
        $changes = $this->preview($pdo, $idEmpresa);
        if ($changes === []) {
            return ['cambios' => [], 'capacidades_efectivas' => []];
        }

        $effective = $this->capabilities->applyEffectiveCapabilities($pdo, $idEmpresa);
        $this->audit($pdo, $idEmpresa, $actorEmail, $changes);
        return ['cambios' => $changes, 'capacidades_efectivas' => $effective];
    }

    /**
     * @param array<string,bool> $current
     * @param array<string,bool> $expected
     * @return list<array{codigo_capacidad:string,actual:?bool,esperado:bool}>
     */
    public static function diff(array $current, array $expected): array
    {
        $changes = [];
        foreach ($expected as $code => $enabled) {
            $actual = array_key_exists($code, $current) ? (bool)$current[$code] : null;
            if ($actual !== (bool)$enabled) {
                $changes[] = [
                    'codigo_capacidad' => (string)$code,
                    'actual' => $actual,
                    'esperado' => (bool)$enabled,
                ];
            }
        }
        usort($changes, static fn(array $left, array $right): int => $left['codigo_capacidad'] <=> $right['codigo_capacidad']);
        return $changes;
    }

    /** @return array<string,bool> */
    private function materializedCapabilities(PDO $pdo, int $idEmpresa): array
    {
        $st = $pdo->prepare('
            SELECT codigo_capacidad, enabled
            FROM pos_saas.empresa_capacidad
            WHERE id_empresa = :empresa
        ');
        $st->execute([':empresa' => $idEmpresa]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string)$row['codigo_capacidad']] = $this->toBool($row['enabled'] ?? false);
        }
        return $out;
    }

    /** @param list<array{codigo_capacidad:string,actual:?bool,esperado:bool}> $changes */
    private function audit(PDO $pdo, int $idEmpresa, string $actorEmail, array $changes): void
    {
        $st = $pdo->prepare('
            INSERT INTO admin.audit_log (
                actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent
            ) VALUES (
                NULL, :email, :action, \'empresa\', :empresa, :before::jsonb, :after::jsonb, NULL, :user_agent
            )
        ');
        $st->execute([
            ':email' => $actorEmail,
            ':action' => 'SAAS_CAPACIDADES_RECONCILIADAS_CLI',
            ':empresa' => $idEmpresa,
            ':before' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':after' => json_encode(['reconciliadas' => $changes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':user_agent' => 'cli:reconcile_saas_capabilities',
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }
}

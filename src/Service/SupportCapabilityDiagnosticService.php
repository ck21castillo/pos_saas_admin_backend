<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use PDO;

final class SupportCapabilityDiagnosticService
{
    /** @var array<int,string> */
    public const OPEN_ORDER_STATES = ['RECIBIDO', 'EN_PROCESO', 'LISTO'];

    public function __construct(private ?PDO $tenant = null)
    {
    }

    /** @return array<string,mixed> */
    public function diagnose(int $idEmpresa): array
    {
        try {
            $tenant = $this->tenant ?? (new AdminTenantSyncService())->tenantConnectionForCompany($idEmpresa);
            if ($tenant === null) {
                return $this->unavailable('TENANT_SIN_CONFIGURACION');
            }
        } catch (\Throwable) {
            return $this->unavailable('TENANT_NO_DISPONIBLE');
        }

        try {
            $exists = $tenant->query("SELECT to_regclass('pos_saas.soporte_orden') IS NOT NULL")->fetchColumn();
            if (!$exists) {
                return $this->empty();
            }

            $capabilityExists = $tenant->query("SELECT to_regclass('pos_saas.empresa_capacidad') IS NOT NULL")->fetchColumn();
            $tenantCapabilityEnabled = false;
            if ($capabilityExists) {
                $capability = $tenant->prepare('
                    SELECT enabled
                    FROM pos_saas.empresa_capacidad
                    WHERE id_empresa = :empresa AND codigo_capacidad = :codigo
                    LIMIT 1
                ');
                $capability->execute([':empresa' => $idEmpresa, ':codigo' => 'SOPORTE_TECNICO']);
                $tenantCapabilityEnabled = BusinessConfigService::toBool($capability->fetchColumn());
            }

            $states = $tenant->prepare('
                SELECT UPPER(estado) AS estado, COUNT(*) AS total
                FROM pos_saas.soporte_orden
                WHERE id_empresa = :empresa
                  AND UPPER(estado) = ANY(:states::text[])
                GROUP BY UPPER(estado)
                ORDER BY UPPER(estado)
            ');
            $states->execute([
                ':empresa' => $idEmpresa,
                ':states' => '{' . implode(',', self::OPEN_ORDER_STATES) . '}',
            ]);
            $byState = [];
            $total = 0;
            foreach ($states->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $state = (string)$row['estado'];
                $count = (int)$row['total'];
                $byState[$state] = $count;
                $total += $count;
            }

            $sample = [];
            if ($total > 0) {
                $orders = $tenant->prepare('
                    SELECT so.id_soporte, so.numero, so.estado, so.total, so.pagado_total,
                           COALESCE(NULLIF(TRIM(c.nombre), \'\'), NULLIF(to_jsonb(so)->>\'cliente_nombre\', \'\')) AS cliente,
                           COALESCE(to_jsonb(so)->>\'fecha\', to_jsonb(so)->>\'created_at\') AS fecha
                    FROM pos_saas.soporte_orden so
                    LEFT JOIN pos_saas.cliente c
                      ON c.id_empresa = so.id_empresa AND c.id_cliente = so.id_cliente
                    WHERE so.id_empresa = :empresa
                      AND UPPER(so.estado) = ANY(:states::text[])
                    ORDER BY so.id_soporte ASC
                    LIMIT 20
                ');
                $orders->execute([
                    ':empresa' => $idEmpresa,
                    ':states' => '{' . implode(',', self::OPEN_ORDER_STATES) . '}',
                ]);
                $sample = array_map(static fn (array $row): array => [
                    'id_soporte' => (int)$row['id_soporte'],
                    'numero' => (string)$row['numero'],
                    'estado' => (string)$row['estado'],
                    'total' => (float)$row['total'],
                    'pagado_total' => (float)$row['pagado_total'],
                    'cliente' => $row['cliente'] !== null ? (string)$row['cliente'] : null,
                    'fecha' => $row['fecha'] !== null ? (string)$row['fecha'] : null,
                ], $orders->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }

            return [
                'codigo' => $total > 0 ? 'SOPORTE_TECNICO_ORDENES_ABIERTAS' : null,
                'bloquea_desactivacion' => $total > 0,
                'mensaje' => $total > 0
                    ? 'Hay ordenes de soporte abiertas que deben cerrarse o corregirse antes de deshabilitar la capacidad.'
                    : null,
                'ordenes_abiertas' => $total,
                'por_estado' => $byState,
                'muestra_ordenes' => $sample,
                'muestra' => $sample,
                'capacidad_activa_tenant' => $tenantCapabilityEnabled,
            ];
        } catch (\Throwable) {
            return $this->unavailable('TENANT_DIAGNOSTICO_FALLIDO');
        }
    }

    /** @return array<string,mixed> */
    private function empty(): array
    {
        return [
            'codigo' => null,
            'bloquea_desactivacion' => false,
            'mensaje' => null,
            'ordenes_abiertas' => 0,
            'por_estado' => [],
            'muestra_ordenes' => [],
            'muestra' => [],
            'capacidad_activa_tenant' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $code): array
    {
        return [
            'codigo' => $code,
            'bloquea_desactivacion' => true,
            'mensaje' => 'No fue posible verificar las ordenes de soporte; la desactivacion queda bloqueada por seguridad.',
            'ordenes_abiertas' => null,
            'por_estado' => [],
            'muestra_ordenes' => [],
            'muestra' => [],
            'capacidad_activa_tenant' => false,
        ];
    }
}

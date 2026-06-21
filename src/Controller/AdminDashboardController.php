<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminDashboardController
{
    public function summary(): void
    {
        $pdo = Database::getConnection();

        $companyStats = $this->companyStats($pdo);
        $requests = $this->pendingRequests($pdo);
        $tickets = $this->openTickets($pdo);
        $tenants = $this->tenantWarnings($pdo);
        $visits = $this->landingVisits($pdo);

        Response::json([
            'ok' => true,
            'kpis' => [
                'empresas_activas' => $companyStats['activas'],
                'empresas_inactivas' => $companyStats['inactivas'],
                'solicitudes_pendientes' => $requests['total'],
                'tickets_abiertos' => $tickets['total'],
                'tenants_advertencia' => $tenants['total'],
                'visitas_hoy' => $visits['today'],
                'visitas_7d' => $visits['last_7d'],
            ],
            'pending_requests' => $requests['items'],
            'open_tickets' => $tickets['items'],
            'tenant_warnings' => $tenants['items'],
            'recent_visits' => $visits['items'],
            'updated_at' => gmdate('c'),
        ]);
    }

    private function tableExists(PDO $pdo, string $qualifiedName): bool
    {
        $st = $pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
        $st->execute([':name' => $qualifiedName]);
        return (bool)$st->fetchColumn();
    }

    /** @return array{activas:int,inactivas:int} */
    private function companyStats(PDO $pdo): array
    {
        $row = $pdo->query(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE LOWER(COALESCE(estado::text, '')) IN ('1', 't', 'true', 'active', 'activa')) AS activas,
                COUNT(*) FILTER (WHERE LOWER(COALESCE(estado::text, '')) NOT IN ('1', 't', 'true', 'active', 'activa')) AS inactivas
            FROM pos_saas.empresa
        SQL)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'activas' => (int)($row['activas'] ?? 0),
            'inactivas' => (int)($row['inactivas'] ?? 0),
        ];
    }

    /** @return array{total:int,items:array<int,array<string,mixed>>} */
    private function pendingRequests(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'admin.invitation_request')) {
            return ['total' => 0, 'items' => []];
        }

        $totalQ = $pdo->query("SELECT COUNT(*) FROM admin.invitation_request WHERE estado = 'PENDIENTE'");
        $itemsQ = $pdo->query(" 
            SELECT id_request, email, empresa_nombre, telefono, plan_solicitado, created_at
            FROM admin.invitation_request
            WHERE estado = 'PENDIENTE'
            ORDER BY created_at DESC, id_request DESC
            LIMIT 5
        ");

        return [
            'total' => (int)$totalQ->fetchColumn(),
            'items' => $itemsQ->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /** @return array{total:int,items:array<int,array<string,mixed>>} */
    private function openTickets(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'admin.help_ticket')) {
            return ['total' => 0, 'items' => []];
        }

        $where = "UPPER(COALESCE(t.estado, '')) <> 'CERRADO'";

        $totalQ = $pdo->query("SELECT COUNT(*) FROM admin.help_ticket t WHERE {$where}");
        $itemsQ = $pdo->query(" 
            SELECT
                t.id_ticket, t.id_empresa, e.nombre AS empresa_nombre,
                t.contacto_nombre, t.contacto_email, t.asunto,
                t.estado, t.prioridad, t.created_at, t.updated_at
            FROM admin.help_ticket t
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = t.id_empresa
            WHERE {$where}
            ORDER BY t.updated_at DESC NULLS LAST, t.created_at DESC, t.id_ticket DESC
            LIMIT 5
        ");

        return [
            'total' => (int)$totalQ->fetchColumn(),
            'items' => $itemsQ->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /** @return array{total:int,items:array<int,array<string,mixed>>} */
    private function tenantWarnings(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'admin.tenant_database')) {
            $items = $pdo->query(" 
                SELECT id_empresa, nombre AS empresa_nombre,
                       NULL::text AS db_name,
                       'SIN_TABLA_TENANT'::text AS estado,
                       'SIN_TABLA_TENANT'::text AS motivo,
                       NULL::timestamp AS updated_at
                FROM pos_saas.empresa
                ORDER BY id_empresa DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return ['total' => count($items), 'items' => $items];
        }

        $warningSql = "
            FROM pos_saas.empresa e
            LEFT JOIN admin.tenant_database t ON t.id_empresa = e.id_empresa
            WHERE t.id_empresa IS NULL
               OR COALESCE(NULLIF(TRIM(t.db_name), ''), '') = ''
               OR UPPER(COALESCE(t.estado, '')) <> 'ACTIVE'
        ";

        $totalQ = $pdo->query('SELECT COUNT(*) ' . $warningSql);
        $itemsQ = $pdo->query(" 
            SELECT
                e.id_empresa,
                e.nombre AS empresa_nombre,
                t.db_name,
                COALESCE(t.estado, 'SIN_MAPPING') AS estado,
                CASE
                    WHEN t.id_empresa IS NULL THEN 'SIN_MAPPING'
                    WHEN COALESCE(NULLIF(TRIM(t.db_name), ''), '') = '' THEN 'SIN_DB_NAME'
                    WHEN UPPER(COALESCE(t.estado, '')) <> 'ACTIVE' THEN 'MAPPING_NO_ACTIVE'
                    ELSE 'OK'
                END AS motivo,
                t.updated_at
            {$warningSql}
            ORDER BY e.id_empresa DESC
            LIMIT 10
        ");

        return [
            'total' => (int)$totalQ->fetchColumn(),
            'items' => $itemsQ->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /** @return array{today:int,last_7d:int,items:array<int,array<string,mixed>>} */
    private function landingVisits(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'admin.landing_visit')) {
            return ['today' => 0, 'last_7d' => 0, 'items' => []];
        }

        $totals = $pdo->query(" 
            SELECT
                COUNT(*) FILTER (WHERE created_at >= date_trunc('day', now())) AS visits_today,
                COUNT(*) FILTER (WHERE created_at >= now() - interval '7 days') AS visits_7d
            FROM admin.landing_visit
            WHERE created_at >= now() - interval '7 days'
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $items = $pdo->query(" 
            SELECT id_visit, visitor_id, landing_path, referrer, page_location, created_at
            FROM admin.landing_visit
            ORDER BY created_at DESC, id_visit DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'today' => (int)($totals['visits_today'] ?? 0),
            'last_7d' => (int)($totals['visits_7d'] ?? 0),
            'items' => $items,
        ];
    }
}

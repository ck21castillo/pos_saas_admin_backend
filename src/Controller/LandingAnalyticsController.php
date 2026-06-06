<?php

namespace PosAdmin\Controller;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class LandingAnalyticsController
{
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function ensureTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE SCHEMA IF NOT EXISTS admin;

            CREATE TABLE IF NOT EXISTS admin.landing_visit (
                id_visit BIGSERIAL PRIMARY KEY,
                visitor_id VARCHAR(120) NOT NULL,
                landing_path TEXT NOT NULL,
                page_location TEXT NULL,
                referrer TEXT NULL,
                user_agent TEXT NULL,
                ip INET NULL,
                meta JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_landing_visit_created_at
                ON admin.landing_visit (created_at DESC);

            CREATE INDEX IF NOT EXISTS idx_landing_visit_path_created
                ON admin.landing_visit (landing_path, created_at DESC);

            CREATE INDEX IF NOT EXISTS idx_landing_visit_visitor_created
                ON admin.landing_visit (visitor_id, created_at DESC);
        ");
    }

    private function getClientIp(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return null;
        return $ip;
    }

    private function normalizePath(string $path): string
    {
        $p = trim($path);
        if ($p === '') return '/';
        if ($p[0] !== '/') return '/' . $p;
        return $p;
    }

    /** POST /analytics/landing-visit */
    public function ingestVisit(): void
    {
        $body = $this->jsonBody();

        $visitorId = trim((string)($body['visitor_id'] ?? ''));
        $landingPath = $this->normalizePath((string)($body['landing_path'] ?? '/'));
        $pageLocation = trim((string)($body['page_location'] ?? ''));
        $referrer = trim((string)($body['referrer'] ?? ''));
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $ip = $this->getClientIp();

        if ($visitorId === '' || strlen($visitorId) < 8) {
            Response::error('VISITOR_ID_INVALIDO', 422);
        }

        if (!in_array($landingPath, ['/', '/crear-negocio'], true)) {
            Response::error('LANDING_PATH_INVALIDO', 422);
        }

        $utm = [
            'utm_source' => trim((string)($body['utm_source'] ?? '')),
            'utm_medium' => trim((string)($body['utm_medium'] ?? '')),
            'utm_campaign' => trim((string)($body['utm_campaign'] ?? '')),
            'utm_term' => trim((string)($body['utm_term'] ?? '')),
            'utm_content' => trim((string)($body['utm_content'] ?? '')),
        ];

        $pdo = Database::getConnection();
        $this->ensureTable($pdo);

        // Evita doble conteo por reintentos o doble render estricto en una ventana corta.
        $dedupe = $pdo->prepare("
            SELECT 1
            FROM admin.landing_visit
            WHERE visitor_id = :visitor_id
              AND landing_path = :landing_path
              AND created_at >= now() - interval '15 seconds'
            LIMIT 1
        ");
        $dedupe->execute([
            ':visitor_id' => $visitorId,
            ':landing_path' => $landingPath,
        ]);

        if ($dedupe->fetchColumn()) {
            Response::json(['ok' => true, 'deduped' => true]);
        }

        $insert = $pdo->prepare("
            INSERT INTO admin.landing_visit (
                visitor_id,
                landing_path,
                page_location,
                referrer,
                user_agent,
                ip,
                meta
            )
            VALUES (
                :visitor_id,
                :landing_path,
                :page_location,
                :referrer,
                :user_agent,
                CAST(NULLIF(:ip, '') AS inet),
                :meta::jsonb
            )
        ");

        $insert->execute([
            ':visitor_id' => $visitorId,
            ':landing_path' => $landingPath,
            ':page_location' => ($pageLocation !== '' ? $pageLocation : null),
            ':referrer' => ($referrer !== '' ? $referrer : null),
            ':user_agent' => ($userAgent !== '' ? $userAgent : null),
            ':ip' => $ip ?? '',
            ':meta' => json_encode($utm, JSON_UNESCAPED_UNICODE),
        ]);

        Response::json(['ok' => true], 201);
    }

    /** GET /admin/analytics/landing-visits?days=30 */
    public function summary(): void
    {
        $days = (int)($_GET['days'] ?? 30);
        $days = max(1, min(180, $days));

        $toDate = new DateTimeImmutable('today');
        $fromDate = $toDate->sub(new DateInterval('P' . ($days - 1) . 'D'));
        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');

        $pdo = Database::getConnection();
        $this->ensureTable($pdo);

        $totalsQ = $pdo->query("
            SELECT
                COUNT(*) FILTER (WHERE created_at >= date_trunc('day', now())) AS visits_today,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= date_trunc('day', now())) AS visitors_today,
                COUNT(*) FILTER (WHERE created_at >= now() - interval '7 days') AS visits_7d,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= now() - interval '7 days') AS visitors_7d,
                COUNT(*) FILTER (WHERE created_at >= now() - interval '30 days') AS visits_30d,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= now() - interval '30 days') AS visitors_30d
            FROM admin.landing_visit
        ");
        $totals = $totalsQ->fetch(PDO::FETCH_ASSOC) ?: [];

        $series = $pdo->prepare("
            WITH days AS (
                SELECT generate_series(:from::date, :to::date, interval '1 day')::date AS day
            ),
            agg AS (
                SELECT
                    created_at::date AS day,
                    COUNT(*) AS visits,
                    COUNT(DISTINCT visitor_id) AS visitors
                FROM admin.landing_visit
                WHERE created_at >= :from::date
                  AND created_at < (:to::date + interval '1 day')
                GROUP BY created_at::date
            )
            SELECT
                d.day::text AS day,
                COALESCE(a.visits, 0)::int AS visits,
                COALESCE(a.visitors, 0)::int AS visitors
            FROM days d
            LEFT JOIN agg a ON a.day = d.day
            ORDER BY d.day ASC
        ");
        $series->execute([':from' => $from, ':to' => $to]);
        $daily = $series->fetchAll(PDO::FETCH_ASSOC);

        $pathsQ = $pdo->prepare("
            SELECT
                landing_path,
                COUNT(*)::int AS visits,
                COUNT(DISTINCT visitor_id)::int AS visitors
            FROM admin.landing_visit
            WHERE created_at >= :from::date
              AND created_at < (:to::date + interval '1 day')
            GROUP BY landing_path
            ORDER BY visits DESC
            LIMIT 10
        ");
        $pathsQ->execute([':from' => $from, ':to' => $to]);
        $paths = $pathsQ->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'ok' => true,
            'range' => [
                'from' => $from,
                'to' => $to,
                'days' => $days,
            ],
            'totals' => [
                'visits_today' => (int)($totals['visits_today'] ?? 0),
                'visitors_today' => (int)($totals['visitors_today'] ?? 0),
                'visits_7d' => (int)($totals['visits_7d'] ?? 0),
                'visitors_7d' => (int)($totals['visitors_7d'] ?? 0),
                'visits_30d' => (int)($totals['visits_30d'] ?? 0),
                'visitors_30d' => (int)($totals['visitors_30d'] ?? 0),
            ],
            'daily' => $daily,
            'paths' => $paths,
        ]);
    }
}

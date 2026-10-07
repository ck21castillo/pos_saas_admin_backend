<?php

namespace PosAdmin\Controller;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PDOException;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\LandingAnalyticsInput;
use PosAdmin\Service\LandingAnalyticsRateLimitService;
use PosAdmin\Service\LandingAnalyticsSchemaGuard;
use RuntimeException;
use Throwable;

final class LandingAnalyticsController
{
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function requireSchema(PDO $pdo): void
    {
        (new LandingAnalyticsSchemaGuard())->assertAvailable($pdo);
    }

    private function databaseError(Throwable $error): void
    {
        if ($error instanceof RuntimeException && $error->getMessage() === 'LANDING_ANALYTICS_SCHEMA_MISSING') {
            Response::error('LANDING_ANALYTICS_SCHEMA_MISSING', 503);
        }

        if ($error instanceof PDOException && (string)$error->getCode() === '42P01') {
            Response::error('LANDING_ANALYTICS_SCHEMA_MISSING', 503);
        }

        Response::error('LANDING_ANALYTICS_UNAVAILABLE', 503);
    }

    /** POST /analytics/landing-visit */
    public function ingestVisit(): void
    {
        try {
            $input = LandingAnalyticsInput::normalize(
                $this->jsonBody(),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (RuntimeException $error) {
            Response::error($error->getMessage(), 422);
        }
        $ip = LandingAnalyticsInput::clientIp();

        try {
            $pdo = Database::getConnection();
            $this->requireSchema($pdo);

            $retryAfter = LandingAnalyticsRateLimitService::retryAfterIfLimited($pdo, $ip, (string)$input['visitor_id']);
            if ($retryAfter > 0) {
                header('Retry-After: ' . $retryAfter);
                Response::error('RATE_LIMITED', 429);
            }

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
            ':visitor_id' => $input['visitor_id'],
            ':landing_path' => $input['landing_path'],
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
            ':visitor_id' => $input['visitor_id'],
            ':landing_path' => $input['landing_path'],
            ':page_location' => $input['page_location'],
            ':referrer' => $input['referrer'],
            ':user_agent' => $input['user_agent'],
            ':ip' => $ip ?? '',
            ':meta' => json_encode($input['utm'], JSON_UNESCAPED_UNICODE),
            ]);

            Response::json(['ok' => true], 201);
        } catch (Throwable $error) {
            $this->databaseError($error);
        }
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

        try {
            $pdo = Database::getConnection();
            $this->requireSchema($pdo);

            $totalsQ = $pdo->query("
            SELECT
                COUNT(*) FILTER (WHERE created_at >= date_trunc('day', now())) AS visits_today,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= date_trunc('day', now())) AS visitors_today,
                COUNT(*) FILTER (WHERE created_at >= now() - interval '7 days') AS visits_7d,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= now() - interval '7 days') AS visitors_7d,
                COUNT(*) FILTER (WHERE created_at >= now() - interval '30 days') AS visits_30d,
                COUNT(DISTINCT visitor_id) FILTER (WHERE created_at >= now() - interval '30 days') AS visitors_30d
            FROM admin.landing_visit
            WHERE created_at >= now() - interval '30 days'
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
        } catch (Throwable $error) {
            $this->databaseError($error);
        }
    }
}

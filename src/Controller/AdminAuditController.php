<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminAuditController
{
    public function list(): void
    {
        try {
            $pdo = Database::getConnection();
            if (!$this->tableExists($pdo, 'admin', 'audit_log')) {
                Response::json([
                    'ok' => true,
                    'total' => 0,
                    'limit' => 25,
                    'offset' => 0,
                    'items' => [],
                    'actions' => [],
                    'message' => 'AUDIT_TABLE_NOT_FOUND',
                ]);
            }

            $columns = $this->columns($pdo, 'admin', 'audit_log');
            $idColumn = $this->firstExisting($columns, ['id_audit_log', 'id_audit', 'id_log', 'id']);
            $createdColumn = $this->firstExisting($columns, ['created_at', 'fecha', 'timestamp', 'logged_at']);

            $q = trim((string)($_GET['q'] ?? ''));
            $action = strtoupper(trim((string)($_GET['action'] ?? '')));
            $targetType = trim((string)($_GET['target_type'] ?? ''));
            $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            [$whereSql, $params] = $this->buildWhere($columns, $q, $action, $targetType, $idEmpresa, $from, $to, $createdColumn);

            $count = $pdo->prepare('SELECT COUNT(*) FROM admin.audit_log a' . $whereSql);
            foreach ($params as $key => $value) {
                $count->bindValue($key, $value);
            }
            $count->execute();
            $total = (int)$count->fetchColumn();

            $select = $this->selectSql($columns, $idColumn, $createdColumn);
            $order = $this->orderSql($idColumn, $createdColumn);
            $sql = "
                SELECT {$select}
                FROM admin.audit_log a
                {$whereSql}
                {$order}
                LIMIT :limit OFFSET :offset
            ";
            $st = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $st->bindValue($key, $value);
            }
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            $st->execute();

            Response::json([
                'ok' => true,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'actions' => $this->actions($pdo, $columns),
            ]);
        } catch (\Throwable $e) {
            $payload = ['error' => 'AUDIT_LIST_FAILED'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }

    /** @param array<string, true> $columns */
    private function buildWhere(array $columns, string $q, string $action, string $targetType, int $idEmpresa, string $from, string $to, ?string $createdColumn): array
    {
        $where = [];
        $params = [];

        if ($action !== '' && isset($columns['action'])) {
            $where[] = 'a.action = :action';
            $params[':action'] = $action;
        }

        if ($targetType !== '' && isset($columns['target_type'])) {
            $where[] = 'a.target_type = :target_type';
            $params[':target_type'] = $targetType;
        }

        if ($idEmpresa > 0 && isset($columns['target_type'], $columns['target_id'])) {
            $where[] = "a.target_type = 'empresa' AND a.target_id = :empresa";
            $params[':empresa'] = $idEmpresa;
        }

        if ($createdColumn !== null) {
            if ($from !== '') {
                $where[] = 'a.' . $this->qi($createdColumn) . ' >= CAST(:from AS timestamptz)';
                $params[':from'] = $from;
            }
            if ($to !== '') {
                $where[] = 'a.' . $this->qi($createdColumn) . ' < (CAST(:to AS date) + interval ' . "'1 day')";
                $params[':to'] = $to;
            }
        }

        if ($q !== '') {
            $parts = [];
            foreach (['actor_email', 'action', 'target_type', 'ip'] as $column) {
                if (isset($columns[$column])) {
                    $parts[] = 'CAST(a.' . $this->qi($column) . ' AS text) ILIKE :q';
                }
            }
            if (isset($columns['target_id']) && ctype_digit($q)) {
                $parts[] = 'a.target_id = :q_id';
                $params[':q_id'] = (int)$q;
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
                $params[':q'] = '%' . $q . '%';
            }
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /** @param array<string, true> $columns */
    private function selectSql(array $columns, ?string $idColumn, ?string $createdColumn): string
    {
        $fields = [];
        $fields[] = $idColumn !== null ? 'a.' . $this->qi($idColumn) . ' AS id_audit' : 'NULL::bigint AS id_audit';
        foreach (['actor_id', 'actor_email', 'action', 'target_type', 'target_id', 'ip', 'user_agent'] as $column) {
            $fields[] = isset($columns[$column])
                ? 'CAST(a.' . $this->qi($column) . ' AS text) AS ' . $this->qi($column)
                : 'NULL::text AS ' . $this->qi($column);
        }
        $fields[] = isset($columns['before']) ? 'a."before"::text AS before_json' : 'NULL::text AS before_json';
        $fields[] = isset($columns['after']) ? 'a."after"::text AS after_json' : 'NULL::text AS after_json';
        $fields[] = $createdColumn !== null ? 'a.' . $this->qi($createdColumn) . ' AS created_at' : 'NULL::timestamptz AS created_at';
        return implode(', ', $fields);
    }

    private function orderSql(?string $idColumn, ?string $createdColumn): string
    {
        $parts = [];
        if ($createdColumn !== null) {
            $parts[] = 'a.' . $this->qi($createdColumn) . ' DESC NULLS LAST';
        }
        if ($idColumn !== null) {
            $parts[] = 'a.' . $this->qi($idColumn) . ' DESC';
        }
        return $parts ? 'ORDER BY ' . implode(', ', $parts) : '';
    }

    /** @param array<string, true> $columns */
    private function actions(PDO $pdo, array $columns): array
    {
        if (!isset($columns['action'])) {
            return [];
        }
        $st = $pdo->query('SELECT DISTINCT action FROM admin.audit_log WHERE action IS NOT NULL ORDER BY action ASC LIMIT 50');
        return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }

    private function tableExists(PDO $pdo, string $schema, string $table): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t LIMIT 1');
        $st->execute([':s' => $schema, ':t' => $table]);
        return (bool)$st->fetchColumn();
    }

    /** @return array<string, true> */
    private function columns(PDO $pdo, string $schema, string $table): array
    {
        $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = :s AND table_name = :t');
        $st->execute([':s' => $schema, ':t' => $table]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
            $out[(string)$column] = true;
        }
        return $out;
    }

    /** @param array<string, true> $columns */
    private function firstExisting(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    private function qi(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

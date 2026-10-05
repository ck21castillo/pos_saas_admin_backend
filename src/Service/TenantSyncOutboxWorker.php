<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use Closure;

final class TenantSyncOutboxWorker
{
    /** @var Closure(int): array<int, array<string, mixed>> */
    private Closure $retryPending;

    /** @param null|callable(int): array<int, array<string, mixed>> $retryPending */
    public function __construct(?callable $retryPending = null)
    {
        $this->retryPending = Closure::fromCallable(
            $retryPending ?? static fn(int $limit): array => (new AdminTenantSyncService())->retryPendingBusinessConfigs($limit)
        );
    }

    public static function normalizeLimit(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 50;
        }
        if (!preg_match('/^\d+$/', trim($value))) {
            throw new \InvalidArgumentException('LIMIT_INVALID');
        }
        return max(1, min(200, (int)$value));
    }

    /** @return array<string, mixed> */
    public function run(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $results = ($this->retryPending)($limit);
        $summary = [
            'limit' => $limit,
            'total_procesadas' => 0,
            'sincronizadas' => 0,
            'pendientes' => 0,
            'fallidas' => 0,
            'errores_por_empresa' => [],
        ];

        foreach ($results as $result) {
            $summary['total_procesadas']++;
            $state = strtoupper((string)($result['estado'] ?? 'PENDIENTE'));
            if ($state === 'SINCRONIZADO') {
                $summary['sincronizadas']++;
                continue;
            }

            $summary['pendientes']++;
            $error = trim((string)($result['ultimo_error'] ?? $result['detalle'] ?? ''));
            if ($error === '') {
                continue;
            }

            $summary['fallidas']++;
            $summary['errores_por_empresa'][] = [
                'id_empresa' => isset($result['id_empresa']) ? (int)$result['id_empresa'] : null,
                'error' => $this->safeError($error),
            ];
        }

        return $summary;
    }

    /** @param array<string, mixed> $summary */
    public function cronOutput(array $summary): string
    {
        $lines = [sprintf(
            'tenant_sync_outbox limit=%d procesadas=%d sincronizadas=%d pendientes=%d fallidas=%d',
            (int)($summary['limit'] ?? 0),
            (int)($summary['total_procesadas'] ?? 0),
            (int)($summary['sincronizadas'] ?? 0),
            (int)($summary['pendientes'] ?? 0),
            (int)($summary['fallidas'] ?? 0),
        )];

        foreach (($summary['errores_por_empresa'] ?? []) as $error) {
            $companyId = isset($error['id_empresa']) ? (string)$error['id_empresa'] : 'desconocida';
            $lines[] = 'tenant_sync_outbox_error empresa=' . $companyId . ' error=' . (string)($error['error'] ?? 'SIN_DETALLE');
        }

        return implode(PHP_EOL, $lines);
    }

    private function safeError(string $error): string
    {
        $error = preg_replace('/\b(password|pwd|user(name)?)\s*=\s*[^;\s]+/i', '$1=[redacted]', $error) ?? $error;
        $error = preg_replace('#\b(?:postgres(?:ql)?|mysql)://[^\s]+#i', '[redacted-dsn]', $error) ?? $error;
        $error = preg_replace('/\s+/', ' ', trim($error)) ?? $error;
        return substr($error, 0, 300);
    }
}

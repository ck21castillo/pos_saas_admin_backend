<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Core\Database;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\SaasCapabilityReconciliationService;

function reconcileOutput(array $result): string
{
    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function reconcileSafeError(Throwable $error): string
{
    $message = preg_replace('/\b(password|pwd|user(name)?)\s*=\s*[^;\s]+/i', '$1=[redacted]', $error->getMessage()) ?? $error->getMessage();
    $message = preg_replace('#\b(?:postgres(?:ql)?|mysql)://[^\s]+#i', '[redacted-dsn]', $message) ?? $message;
    return substr(preg_replace('/\s+/', ' ', trim($message)) ?? $message, 0, 300);
}

try {
    $options = getopt('', ['dry-run', 'apply', 'actor::']);
    $dryRun = array_key_exists('dry-run', $options);
    $apply = array_key_exists('apply', $options);
    if ($dryRun === $apply) {
        throw new InvalidArgumentException('USAR_EXACTAMENTE_DRY_RUN_O_APPLY');
    }

    $actor = trim((string)($options['actor'] ?? 'system'));
    if ($actor === '' || mb_strlen($actor) > 120) {
        throw new InvalidArgumentException('ACTOR_CLI_INVALIDO');
    }

    $pdo = Database::getConnection();
    $reconciler = new SaasCapabilityReconciliationService();
    $sync = new AdminTenantSyncService();
    $summary = [
        'modo' => $dryRun ? 'DRY_RUN' : 'APPLY',
        'empresas_revisadas' => 0,
        'empresas_con_cambios' => 0,
        'sincronizadas' => 0,
        'pendientes' => 0,
        'errores' => 0,
        'items' => [],
    ];

    foreach ($reconciler->commercialCompanies($pdo) as $company) {
        $idEmpresa = (int)$company['id_empresa'];
        $summary['empresas_revisadas']++;
        try {
            $changes = $reconciler->preview($pdo, $idEmpresa);
            $item = [
                'id_empresa' => $idEmpresa,
                'plan' => (string)$company['plan_codigo'],
                'cambios' => $changes,
            ];
            if ($changes === []) {
                $item['estado'] = 'SIN_CAMBIOS';
                $summary['items'][] = $item;
                continue;
            }

            $summary['empresas_con_cambios']++;
            if ($dryRun) {
                $item['estado'] = 'PENDIENTE_APLICAR';
                $summary['items'][] = $item;
                continue;
            }

            $pdo->beginTransaction();
            try {
                // Recheck inside the transaction to keep repeat runs idempotent.
                $applied = $reconciler->apply($pdo, $idEmpresa, $actor);
                if ($applied['cambios'] !== []) {
                    $sync->enqueueBusinessConfig($pdo, $idEmpresa);
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }

            if ($applied['cambios'] === []) {
                $item['estado'] = 'SIN_CAMBIOS';
                $summary['empresas_con_cambios']--;
                $summary['items'][] = $item;
                continue;
            }

            $syncResult = $sync->processBusinessConfigForCompany($idEmpresa);
            $syncState = strtoupper((string)($syncResult['estado'] ?? 'PENDIENTE'));
            $item['estado'] = $syncState === 'SINCRONIZADO' ? 'APLICADO_SINCRONIZADO' : 'APLICADO_PENDIENTE';
            $item['sync'] = [
                'estado' => $syncState,
                'detalle' => $syncResult['detalle'] ?? null,
            ];
            if ($syncState === 'SINCRONIZADO') {
                $summary['sincronizadas']++;
            } else {
                $summary['pendientes']++;
            }
            $summary['items'][] = $item;
        } catch (Throwable $error) {
            $summary['errores']++;
            $summary['items'][] = [
                'id_empresa' => $idEmpresa,
                'plan' => (string)$company['plan_codigo'],
                'estado' => 'ERROR',
                'error' => reconcileSafeError($error),
            ];
        }
    }

    fwrite(STDOUT, reconcileOutput($summary) . PHP_EOL);
    exit($summary['errores'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'saas_capability_reconcile_fatal error=' . reconcileSafeError($error) . PHP_EOL);
    exit(1);
}

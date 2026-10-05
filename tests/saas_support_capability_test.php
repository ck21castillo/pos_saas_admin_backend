<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PosAdmin\Core\Database;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\BusinessConfigService;
use PosAdmin\Service\SaasCapabilityDisableBlockedException;
use PosAdmin\Service\SaasCapabilityService;
use PosAdmin\Service\SupportCapabilityDiagnosticService;

function expectSupport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function supportCapability(array $items): array
{
    foreach ($items as $item) {
        if (($item['codigo_capacidad'] ?? null) === 'SOPORTE_TECNICO') {
            return $item;
        }
    }
    throw new RuntimeException('DETALLE_SOPORTE_NO_ENCONTRADO');
}

$pdo = Database::getConnection();
$companyId = (int)$pdo->query("SELECT id_empresa FROM admin.tenant_database WHERE db_name = 'bersano_tenant_1' LIMIT 1")->fetchColumn();
expectSupport($companyId > 0, 'BERSANO_TENANT_1_NO_ENCONTRADO');
expectSupport((bool)$pdo->query("SELECT 1 FROM pos_saas.capacidad WHERE codigo_capacidad = 'SOPORTE_TECNICO' AND estado = 1")->fetchColumn(), 'SOPORTE_TECNICO_NO_ESTA_EN_CATALOGO');
expectSupport((bool)$pdo->query("SELECT 1 FROM admin.saas_capacidad_modulo cm JOIN pos_saas.modulo m ON m.id_modulo = cm.id_modulo WHERE cm.codigo_capacidad = 'SOPORTE_TECNICO' AND m.ruta = '/soporte'")->fetchColumn(), 'MODULO_SOPORTE_NO_MAPEADO');
expectSupport((bool)$pdo->query("SELECT 1 FROM admin.saas_capacidad_permiso cm JOIN pos_saas.permiso p ON p.id_permiso = cm.id_permiso WHERE cm.codigo_capacidad = 'SOPORTE_TECNICO' AND p.codigo = 'WHATSAPP__SOPORTE_ENVIAR_COMPROBANTE'")->fetchColumn(), 'WHATSAPP_SOPORTE_NO_MAPEADO');
expectSupport(SupportCapabilityDiagnosticService::OPEN_ORDER_STATES === ['RECIBIDO', 'EN_PROCESO', 'LISTO'], 'ESTADOS_ABIERTOS_SOPORTE_INVALIDOS');

$service = new SaasCapabilityService();
$diagnostic = new SupportCapabilityDiagnosticService();
$moduleId = (int)$pdo->query("SELECT id_modulo FROM pos_saas.modulo WHERE ruta = '/soporte' LIMIT 1")->fetchColumn();
$permissionId = (int)$pdo->query("SELECT id_permiso FROM pos_saas.permiso WHERE codigo = 'SOPORTE__VER' LIMIT 1")->fetchColumn();
expectSupport($moduleId > 0 && $permissionId > 0, 'RECURSOS_SOPORTE_NO_ENCONTRADOS');

$pdo->beginTransaction();
try {
    $planLookup = $pdo->prepare('SELECT id_plan FROM admin.saas_suscripcion WHERE id_empresa = :empresa LIMIT 1');
    $planLookup->execute([':empresa' => $companyId]);
    $planId = (int)($planLookup->fetchColumn() ?: 0);
    if ($planId > 0) {
        $planPayload = [];
        foreach ($service->planCapabilities($pdo, $planId) as $capability) {
            $planPayload[(string)$capability['codigo_capacidad']] = !empty($capability['incluida']);
        }
        $service->savePlanCapabilities($pdo, $planId, $planPayload, 987, 'test@local');
        $auditPlan = $pdo->prepare("SELECT 1 FROM admin.audit_log WHERE action = 'SAAS_PLAN_CAPACIDADES_SAVE' AND target_type = 'saas_plan' AND target_id = :plan LIMIT 1");
        $auditPlan->execute([':plan' => $planId]);
        expectSupport((bool)$auditPlan->fetchColumn(), 'MATRIZ_PLAN_SIN_AUDITORIA');
    }

    $service->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['enabled' => true, 'motivo' => 'Validacion transitoria T-B-05'], 0, 'test@local');
    $service->assertModuleCanBeEnabled($pdo, $companyId, $moduleId);
    $service->assertPermissionCanBeEnabled($pdo, $companyId, $permissionId);

    $pdo->prepare('INSERT INTO admin.empresa_modulo (id_empresa, id_modulo, enabled) VALUES (:empresa, :modulo, true) ON CONFLICT (id_empresa, id_modulo) DO UPDATE SET enabled = EXCLUDED.enabled')->execute([':empresa' => $companyId, ':modulo' => $moduleId]);
    $pdo->prepare('INSERT INTO admin.empresa_permiso (id_empresa, id_permiso, enabled) VALUES (:empresa, :permiso, true) ON CONFLICT (id_empresa, id_permiso) DO UPDATE SET enabled = EXCLUDED.enabled')->execute([':empresa' => $companyId, ':permiso' => $permissionId]);
    $pdo->prepare('UPDATE admin.empresa_modulo SET enabled = false WHERE id_empresa = :empresa AND id_modulo = :modulo')->execute([':empresa' => $companyId, ':modulo' => $moduleId]);
    $pdo->prepare('UPDATE admin.empresa_permiso SET enabled = false WHERE id_empresa = :empresa AND id_permiso = :permiso')->execute([':empresa' => $companyId, ':permiso' => $permissionId]);
    $service->applyEffectiveCapabilities($pdo, $companyId);

    $moduleEnabled = $pdo->prepare('SELECT enabled FROM admin.empresa_modulo WHERE id_empresa = :empresa AND id_modulo = :modulo');
    $moduleEnabled->execute([':empresa' => $companyId, ':modulo' => $moduleId]);
    expectSupport(!BusinessConfigService::toBool($moduleEnabled->fetchColumn()), 'MODULO_LOCAL_FUE_REACTIVADO');
    $permissionEnabled = $pdo->prepare('SELECT enabled FROM admin.empresa_permiso WHERE id_empresa = :empresa AND id_permiso = :permiso');
    $permissionEnabled->execute([':empresa' => $companyId, ':permiso' => $permissionId]);
    expectSupport(!BusinessConfigService::toBool($permissionEnabled->fetchColumn()), 'PERMISO_LOCAL_FUE_REACTIVADO');

    $service->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['enabled' => false, 'motivo' => 'Validacion de techo SaaS T-B-05'], 0, 'test@local');
    $blockedModule = false;
    try {
        $service->assertModuleCanBeEnabled($pdo, $companyId, $moduleId);
    } catch (InvalidArgumentException $e) {
        $blockedModule = str_starts_with($e->getMessage(), 'CAPACIDAD_SAAS_REQUERIDA:');
    }
    expectSupport($blockedModule, 'MODULO_SIN_TECHO_SAAS_PERMITIDO');
    $blockedPermission = false;
    try {
        $service->assertPermissionCanBeEnabled($pdo, $companyId, $permissionId);
    } catch (InvalidArgumentException $e) {
        $blockedPermission = str_starts_with($e->getMessage(), 'CAPACIDAD_SAAS_REQUERIDA:');
    }
    expectSupport($blockedPermission, 'PERMISO_SIN_TECHO_SAAS_PERMITIDO');

    $service->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['enabled' => true, 'motivo' => 'Validacion restauracion T-B-05'], 0, 'test@local');
    $service->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['restablecer_plan' => true, 'motivo' => 'Restaurar plan T-B-05'], 0, 'test@local');
    $exceptionActive = $pdo->prepare("SELECT activa FROM admin.saas_empresa_capacidad_excepcion WHERE id_empresa = :empresa AND codigo_capacidad = 'SOPORTE_TECNICO'");
    $exceptionActive->execute([':empresa' => $companyId]);
    expectSupport(!BusinessConfigService::toBool($exceptionActive->fetchColumn()), 'RESTAURAR_PLAN_DEJO_EXCEPCION_ACTIVA');
    expectSupport((supportCapability($service->companyCapabilities($pdo, $companyId))['origen'] ?? '') !== 'EXCEPCION_ADMINISTRATIVA', 'RESTAURAR_PLAN_NO_RECUPERO_ORIGEN_PLAN');
    expectSupport(array_key_exists('muestra_ordenes', $diagnostic->diagnose($companyId)), 'DIAGNOSTICO_SIN_MUESTRA_ORDENES');
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$tenant = (new AdminTenantSyncService())->tenantConnectionForCompany($companyId);
if ($tenant !== null) {
    $source = $tenant->prepare("SELECT id_soporte FROM pos_saas.soporte_orden WHERE id_empresa = :empresa AND estado IN ('ENTREGADO', 'FACTURADO', 'ANULADO') ORDER BY id_soporte ASC LIMIT 1");
    $source->execute([':empresa' => $companyId]);
    $idSupport = (int)($source->fetchColumn() ?: 0);
    if ($idSupport > 0) {
        $tenant->beginTransaction();
        $pdo->beginTransaction();
        try {
            $tenant->prepare("UPDATE pos_saas.soporte_orden SET estado = 'RECIBIDO' WHERE id_empresa = :empresa AND id_soporte = :soporte")->execute([':empresa' => $companyId, ':soporte' => $idSupport]);
            $guarded = new SaasCapabilityService(new SupportCapabilityDiagnosticService($tenant));
            $guarded->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['enabled' => true, 'motivo' => 'Validacion bloqueo T-B-05'], 0, 'test@local');
            try {
                $guarded->saveException($pdo, $companyId, 'SOPORTE_TECNICO', ['enabled' => false, 'motivo' => 'Validacion bloqueo T-B-05'], 0, 'test@local');
                throw new RuntimeException('ORDEN_ABIERTA_NO_BLOQUEO_DESACTIVACION_SOPORTE');
            } catch (SaasCapabilityDisableBlockedException $e) {
                $block = $e->diagnostic();
                expectSupport(!empty($block['muestra_ordenes']), 'BLOQUEO_SIN_MUESTRA_ORDENES');
                expectSupport(array_key_exists('cliente', $block['muestra_ordenes'][0]), 'MUESTRA_SIN_CLIENTE');
                expectSupport(array_key_exists('fecha', $block['muestra_ordenes'][0]), 'MUESTRA_SIN_FECHA');
            }
            $pdo->rollBack();
            $tenant->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($tenant->inTransaction()) $tenant->rollBack();
            throw $exception;
        }
    }
}

$outbox = new AdminTenantSyncService();
$existingOutbox = $pdo->prepare("SELECT estado, intentos, ultimo_error, proximo_intento_at, sincronizado_at FROM admin.tenant_sync_outbox WHERE id_empresa = :empresa AND tipo = 'BUSINESS_CONFIG'");
$existingOutbox->execute([':empresa' => $companyId]);
$outboxBefore = $existingOutbox->fetch(PDO::FETCH_ASSOC) ?: null;
$originalDbName = null;
try {
    $pdo->beginTransaction();
    $dbName = $pdo->prepare('SELECT db_name FROM admin.tenant_database WHERE id_empresa = :empresa FOR UPDATE');
    $dbName->execute([':empresa' => $companyId]);
    $originalDbName = (string)$dbName->fetchColumn();
    expectSupport($originalDbName !== '', 'TENANT_DB_NAME_VACIO');
    $pdo->prepare('UPDATE admin.tenant_database SET db_name = :db WHERE id_empresa = :empresa')->execute([':db' => 'tb05_sync_failure_' . $companyId, ':empresa' => $companyId]);
    $outbox->enqueueBusinessConfig($pdo, $companyId);
    $pdo->commit();
    $failedSync = $outbox->processBusinessConfigForCompany($companyId);
    expectSupport(($failedSync['estado'] ?? '') === 'PENDIENTE', 'FALLO_SYNC_NO_QUEDO_PENDIENTE');
    expectSupport(!empty($failedSync['ultimo_error']), 'FALLO_SYNC_SIN_ERROR_PERSISTIDO');

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE admin.tenant_database SET db_name = :db WHERE id_empresa = :empresa')->execute([':db' => $originalDbName, ':empresa' => $companyId]);
    $outbox->enqueueBusinessConfig($pdo, $companyId);
    $pdo->commit();
    $retries = $outbox->retryPendingBusinessConfigs(1);
    expectSupport(($retries[0]['estado'] ?? '') === 'SINCRONIZADO', 'REINTENTO_SYNC_NO_SINCRONIZO');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($originalDbName !== null) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE admin.tenant_database SET db_name = :db WHERE id_empresa = :empresa')->execute([':db' => $originalDbName, ':empresa' => $companyId]);
        if ($outboxBefore === null) {
            $pdo->prepare("DELETE FROM admin.tenant_sync_outbox WHERE id_empresa = :empresa AND tipo = 'BUSINESS_CONFIG'")->execute([':empresa' => $companyId]);
        } else {
            $restore = $pdo->prepare("UPDATE admin.tenant_sync_outbox SET estado = :estado, intentos = :intentos, ultimo_error = :error, proximo_intento_at = :next, sincronizado_at = :synced WHERE id_empresa = :empresa AND tipo = 'BUSINESS_CONFIG'");
            $restore->execute([':estado' => $outboxBefore['estado'], ':intentos' => $outboxBefore['intentos'], ':error' => $outboxBefore['ultimo_error'], ':next' => $outboxBefore['proximo_intento_at'], ':synced' => $outboxBefore['sincronizado_at'], ':empresa' => $companyId]);
        }
        $pdo->commit();
    }
}

echo "T-B-05 soporte SaaS: OK\n";

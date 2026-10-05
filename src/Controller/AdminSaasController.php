<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminSaasService;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\SaasCapabilityDisableBlockedException;
use PosAdmin\Service\SaasCapabilityService;

final class AdminSaasController
{
    private AdminSaasService $service;
    private SaasCapabilityService $capabilities;

    public function __construct()
    {
        $this->service = new AdminSaasService();
        $this->capabilities = new SaasCapabilityService();
    }

    public function listPlans(): void
    {
        $pdo = Database::getConnection();
        $active = $this->queryBool('active');
        Response::json([
            'ok' => true,
            'items' => $this->service->listPlans($pdo, $active),
        ]);
    }

    public function createPlan(array $body): void
    {
        $this->write(function () use ($body) {
            $pdo = Database::getConnection();
            $item = $this->service->upsertPlan($pdo, $body);
            return ['item' => $item];
        }, 201);
    }

    public function updatePlan(int $idPlan, array $body): void
    {
        $this->write(function () use ($idPlan, $body) {
            $pdo = Database::getConnection();
            $item = $this->service->upsertPlan($pdo, $body, $idPlan);
            return ['item' => $item];
        });
    }

    public function showPlanPublicProfile(int $idPlan): void
    {
        try {
            Response::json(array_merge(['ok' => true], $this->service->getPlanPublicProfile(Database::getConnection(), $idPlan)));
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    public function savePlanPublicProfile(int $idPlan, array $body): void
    {
        $this->write(function () use ($idPlan, $body) {
            $profile = $this->service->savePlanPublicProfile(
                Database::getConnection(),
                $idPlan,
                $body,
                $this->actorId(),
                $this->actorEmail()
            );
            return $profile;
        });
    }

    public function showPlanCapabilities(int $idPlan): void
    {
        $pdo = Database::getConnection();
        Response::json(['ok' => true, 'items' => $this->capabilities->planCapabilities($pdo, $idPlan)]);
    }

    public function savePlanCapabilities(int $idPlan, array $body): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $items = $this->capabilities->savePlanCapabilities(
                $pdo,
                $idPlan,
                $body['capacidades'] ?? [],
                $this->actorId(),
                $this->actorEmail()
            );
            $companies = $this->capabilities->applyPlanToSubscribers($pdo, $idPlan);
            $sync = new AdminTenantSyncService();
            foreach ($companies as $companyId) {
                $sync->enqueueBusinessConfig($pdo, $companyId);
            }
            $pdo->commit();
            $syncItems = [];
            foreach ($companies as $companyId) {
                $syncItems[] = $sync->processBusinessConfigForCompany($companyId);
            }
            $pending = array_filter($syncItems, static fn (array $item): bool => ($item['estado'] ?? '') !== 'SINCRONIZADO');
            Response::json([
                'ok' => true,
                'items' => $items,
                'empresas_actualizadas' => $companies,
                'sync' => [
                    'estado' => $pending === [] ? 'SINCRONIZADO' : 'PENDIENTE',
                    'items' => $syncItems,
                ],
            ]);
        } catch (SaasCapabilityDisableBlockedException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json([
                'error' => $e->getMessage(),
                'diagnostico_bloqueo' => $e->diagnostic(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->knownError($e);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->serverError($e);
        }
    }

    public function listPaymentChannels(): void
    {
        $pdo = Database::getConnection();
        $active = $this->queryBool('active');
        Response::json([
            'ok' => true,
            'items' => $this->service->listPaymentChannels($pdo, $active),
        ]);
    }

    public function createPaymentChannel(array $body): void
    {
        $this->write(function () use ($body) {
            $pdo = Database::getConnection();
            $item = $this->service->upsertPaymentChannel($pdo, $body);
            return ['item' => $item];
        }, 201);
    }

    public function updatePaymentChannel(int $idCanal, array $body): void
    {
        $this->write(function () use ($idCanal, $body) {
            $pdo = Database::getConnection();
            $item = $this->service->upsertPaymentChannel($pdo, $body, $idCanal);
            return ['item' => $item];
        });
    }

    public function showCompanySubscription(int $idEmpresa): void
    {
        try {
            $pdo = Database::getConnection();
            Response::json(array_merge(
                ['ok' => true],
                $this->service->getCompanySubscription($pdo, $idEmpresa)
            ));
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    public function saveCompanySubscription(int $idEmpresa, array $body): void
    {
        $this->transaction(function () use ($idEmpresa, $body) {
            $pdo = Database::getConnection();
            $item = $this->service->saveCompanySubscription(
                $pdo,
                $idEmpresa,
                $body,
                $this->actorId(),
                $this->actorEmail()
            );
            return ['item' => $item, 'capacidades_efectivas' => $this->capabilities->applyEffectiveCapabilities($pdo, $idEmpresa)];
        }, 200, $idEmpresa);
    }

    public function saveCompanyCapabilityException(int $idEmpresa, string $code, array $body): void
    {
        $this->transaction(function () use ($idEmpresa, $code, $body) {
            $pdo = Database::getConnection();
            return ['capacidades_efectivas' => $this->capabilities->saveException(
                $pdo, $idEmpresa, $code, $body, $this->actorId(), $this->actorEmail()
            )];
        }, 200, $idEmpresa);
    }

    public function registerPayment(int $idEmpresa, array $body): void
    {
        $this->transaction(function () use ($idEmpresa, $body) {
            $pdo = Database::getConnection();
            $result = $this->service->registerPayment(
                $pdo,
                $idEmpresa,
                $body,
                $this->actorId(),
                $this->actorEmail()
            );
            $result['capacidades_efectivas'] = $this->capabilities->applyEffectiveCapabilities($pdo, $idEmpresa);
            return $result;
        }, 201, $idEmpresa);
    }

    public function suspendCompany(int $idEmpresa, array $body): void
    {
        $this->transaction(function () use ($idEmpresa, $body) {
            $pdo = Database::getConnection();
            $item = $this->service->suspendCompany(
                $pdo,
                $idEmpresa,
                (string)($body['motivo'] ?? ''),
                $this->actorId(),
                $this->actorEmail()
            );
            return ['item' => $item];
        }, 200, $idEmpresa);
    }

    public function reactivateCompany(int $idEmpresa): void
    {
        $this->transaction(function () use ($idEmpresa) {
            $pdo = Database::getConnection();
            $item = $this->service->reactivateCompany(
                $pdo,
                $idEmpresa,
                $this->actorId(),
                $this->actorEmail()
            );
            return ['item' => $item];
        }, 200, $idEmpresa);
    }

    public function extendTrial(int $idEmpresa, array $body): void
    {
        $this->transaction(function () use ($idEmpresa, $body) {
            $pdo = Database::getConnection();
            $item = $this->service->extendTrial(
                $pdo,
                $idEmpresa,
                $body,
                $this->actorId(),
                $this->actorEmail()
            );
            return ['item' => $item];
        }, 200, $idEmpresa);
    }

    public function retryPendingSyncs(array $body): void
    {
        $limit = (int)($body['limit'] ?? 50);
        $items = (new AdminTenantSyncService())->retryPendingBusinessConfigs($limit);
        $pending = array_filter($items, static fn (array $item): bool => ($item['estado'] ?? '') !== 'SINCRONIZADO');
        Response::json([
            'ok' => true,
            'sync' => [
                'estado' => $pending === [] ? 'SINCRONIZADO' : 'PENDIENTE',
                'items' => $items,
            ],
        ]);
    }

    private function transaction(callable $callback, int $status = 200, ?int $syncEmpresaId = null): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $payload = $callback();
            $sync = null;
            if ($syncEmpresaId !== null) {
                $sync = new AdminTenantSyncService();
                $sync->enqueueBusinessConfig($pdo, $syncEmpresaId);
            }
            $pdo->commit();

            if ($syncEmpresaId !== null) {
                $payload['sync'] = $sync?->processBusinessConfigForCompany($syncEmpresaId);
            }

            Response::json(array_merge(['ok' => true], $payload), $status);
        } catch (SaasCapabilityDisableBlockedException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json([
                'error' => $e->getMessage(),
                'diagnostico_bloqueo' => $e->diagnostic(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->knownError($e);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->serverError($e);
        }
    }

    private function write(callable $callback, int $status = 200): void
    {
        try {
            Response::json(array_merge(['ok' => true], $callback()), $status);
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    private function knownError(\RuntimeException $e): void
    {
        $code = $e->getMessage();
        $status = match ($code) {
            'EMPRESA_NO_ENCONTRADA', 'PLAN_NO_ENCONTRADO', 'CANAL_NO_ENCONTRADO', 'SUSCRIPCION_NO_ENCONTRADA' => 404,
            default => 400,
        };
        Response::json(['error' => $code], $status);
    }

    private function serverError(\Throwable $e): void
    {
        $payload = ['error' => 'SERVER_ERROR'];
        if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
            $payload['message'] = $e->getMessage();
        }
        Response::json($payload, 500);
    }

    private function queryBool(string $key): bool
    {
        $value = strtolower(trim((string)($_GET[$key] ?? '')));
        return in_array($value, ['1', 'true', 'si', 'yes', 'on'], true);
    }

    private function actorId(): int
    {
        return (int)($_REQUEST['adminId'] ?? 0);
    }

    private function actorEmail(): string
    {
        return (string)($_REQUEST['adminEmail'] ?? '');
    }
}

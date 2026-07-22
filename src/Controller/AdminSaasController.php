<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminSaasService;
use PosAdmin\Service\AdminTenantSyncService;

final class AdminSaasController
{
    private AdminSaasService $service;

    public function __construct()
    {
        $this->service = new AdminSaasService();
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
            return ['item' => $item];
        });
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

    private function transaction(callable $callback, int $status = 200, ?int $syncEmpresaId = null): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $payload = $callback();
            $pdo->commit();

            if ($syncEmpresaId !== null) {
                (new AdminTenantSyncService())->syncCompany($syncEmpresaId);
            }

            Response::json(array_merge(['ok' => true], $payload), $status);
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

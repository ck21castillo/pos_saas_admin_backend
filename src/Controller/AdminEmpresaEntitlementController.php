<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\CompanyEntitlementService;
use PosAdmin\Service\SaasCapabilityService;

final class AdminEmpresaEntitlementController
{
    private CompanyEntitlementService $entitlements;
    private SaasCapabilityService $capabilities;

    public function __construct()
    {
        $this->entitlements = new CompanyEntitlementService();
        $this->capabilities = new SaasCapabilityService();
    }

    public function effectiveAccesses(int $idEmpresa): void
    {
        try {
            Response::json([
                'ok' => true,
                'id_empresa' => $idEmpresa,
                'accesos' => $this->entitlements->effectiveAccesses(
                    Database::getConnection(),
                    $idEmpresa,
                    $this->capabilities
                ),
            ]);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    public function listExceptions(int $idEmpresa): void
    {
        try {
            Response::json([
                'ok' => true,
                'id_empresa' => $idEmpresa,
                'items' => $this->entitlements->listExceptions(Database::getConnection(), $idEmpresa),
            ]);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    /** @param array<string,mixed> $body */
    public function saveException(int $idEmpresa, array $body): void
    {
        $this->write(function (\PDO $pdo) use ($idEmpresa, $body): array {
            $item = $this->entitlements->saveException(
                $pdo,
                $idEmpresa,
                $body,
                $this->actorId(),
                $this->actorEmail()
            );
            return [
                'item' => $item,
                'entitlements_materializados' => $this->entitlements->materializeCurrent($pdo, $idEmpresa),
            ];
        });
    }

    public function deleteException(int $idEmpresa, int $idException): void
    {
        $this->write(function (\PDO $pdo) use ($idEmpresa, $idException): array {
            $result = $this->entitlements->deleteException(
                $pdo,
                $idEmpresa,
                $idException,
                $this->actorId(),
                $this->actorEmail()
            );
            $result['entitlements_materializados'] = $this->entitlements->materializeCurrent($pdo, $idEmpresa);
            return $result;
        });
    }

    private function write(callable $callback): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $payload = $callback($pdo);
            $pdo->commit();
            Response::json(array_merge(['ok' => true], $payload));
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

    private function knownError(\RuntimeException $e): void
    {
        $status = match ($e->getMessage()) {
            'EMPRESA_NO_ENCONTRADA', 'EXCEPCION_NO_ENCONTRADA' => 404,
            'ENTITLEMENT_EXCEPTION_SCHEMA_MISSING' => 503,
            default => 400,
        };
        Response::json(['error' => $e->getMessage()], $status);
    }

    private function serverError(\Throwable $e): void
    {
        $payload = ['error' => 'SERVER_ERROR'];
        if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
            $payload['message'] = $e->getMessage();
        }
        Response::json($payload, 500);
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

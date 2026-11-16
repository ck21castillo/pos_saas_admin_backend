<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\CommercialComplementService;
use PosAdmin\Service\CompanyEntitlementService;
use PosAdmin\Service\SaasCapabilityDisableBlockedException;
use PosAdmin\Service\SaasCapabilityService;

final class AdminEmpresaCommercialController
{
    private CommercialComplementService $complements;
    private CompanyEntitlementService $entitlements;
    private SaasCapabilityService $capabilities;

    public function __construct()
    {
        $this->complements = new CommercialComplementService();
        $this->entitlements = new CompanyEntitlementService();
        $this->capabilities = new SaasCapabilityService();
    }

    public function catalog(): void
    {
        try {
            Response::json(['ok' => true, 'items' => $this->complements->catalog(Database::getConnection())]);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    /** @param array<string,mixed> $body */
    public function updateCatalog(string $code, array $body): void
    {
        $this->catalogWrite(function (PDO $pdo) use ($code, $body): array {
            return ['item' => $this->complements->updateCatalog($pdo, $code, $body, $this->actorId(), $this->actorEmail())];
        });
    }

    public function listCompanyComplements(int $idEmpresa): void
    {
        try {
            Response::json(['ok' => true, 'id_empresa' => $idEmpresa, 'items' => $this->complements->companyActivations(Database::getConnection(), $idEmpresa)]);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    public function eligibleCompanyComplements(int $idEmpresa): void
    {
        try {
            Response::json(['ok' => true, 'id_empresa' => $idEmpresa, 'items' => $this->complements->eligibleForCompany(Database::getConnection(), $idEmpresa)]);
        } catch (\RuntimeException $e) {
            $this->knownError($e);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    /** @param array<string,mixed> $body */
    public function activate(int $idEmpresa, string $code, array $body): void
    {
        $this->commercialWrite($idEmpresa, function (PDO $pdo) use ($idEmpresa, $code, $body): array {
            return ['item' => $this->complements->activate($pdo, $idEmpresa, $code, $body, $this->actorId(), $this->actorEmail())];
        });
    }

    /** @param array<string,mixed> $body */
    public function deactivate(int $idEmpresa, string $code, array $body): void
    {
        $this->commercialWrite($idEmpresa, function (PDO $pdo) use ($idEmpresa, $code, $body): array {
            return ['item' => $this->complements->deactivate($pdo, $idEmpresa, $code, (string)($body['observacion'] ?? ''), $this->actorId(), $this->actorEmail())];
        });
    }

    /** @param array<string,mixed> $body */
    public function restore(int $idEmpresa, string $code, array $body): void
    {
        $this->commercialWrite($idEmpresa, function (PDO $pdo) use ($idEmpresa, $code, $body): array {
            return ['item' => $this->complements->restore($pdo, $idEmpresa, $code, $body, $this->actorId(), $this->actorEmail())];
        });
    }

    /** @param array<string,mixed> $body */
    public function applyInternalException(int $idEmpresa, string $code, array $body): void
    {
        $enabled = filter_var($body['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            Response::json(['error' => 'VALIDATION', 'message' => 'enabled requerido para la excepcion interna'], 422);
        }
        $this->commercialWrite($idEmpresa, function (PDO $pdo) use ($idEmpresa, $code, $body, $enabled): array {
            return ['item' => $this->complements->applyInternalException(
                $pdo,
                $idEmpresa,
                $code,
                $enabled,
                (string)($body['motivo'] ?? ''),
                $this->actorId(),
                $this->actorEmail()
            )];
        });
    }

    private function commercialWrite(int $idEmpresa, callable $callback): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $beforeCapabilities = $this->capabilityMap($this->capabilities->companyCapabilities($pdo, $idEmpresa, false));
            $payload = $callback($pdo);
            $payload['entitlements_materializados'] = $this->entitlements->materializeCurrent($pdo, $idEmpresa);
            $afterCapabilities = $this->capabilities->applyEffectiveCapabilities($pdo, $idEmpresa);
            $capabilitiesChanged = $beforeCapabilities !== $this->capabilityMap($afterCapabilities);
            $sync = null;
            if ($capabilitiesChanged) {
                $sync = new AdminTenantSyncService();
                $sync->enqueueBusinessConfig($pdo, $idEmpresa);
            }
            $pdo->commit();

            $payload['capacidades_efectivas'] = $afterCapabilities;
            $payload['sync'] = $capabilitiesChanged
                ? $sync?->processBusinessConfigForCompany($idEmpresa)
                : ['estado' => 'SINCRONIZADO', 'id_empresa' => $idEmpresa, 'detalle' => 'SIN_CAMBIOS_CAPACIDAD'];
            Response::json(array_merge(['ok' => true], $payload));
        } catch (SaasCapabilityDisableBlockedException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['error' => $e->getMessage(), 'diagnostico_bloqueo' => $e->diagnostic()], 409);
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->knownError($e);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->serverError($e);
        }
    }

    private function catalogWrite(callable $callback): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $payload = $callback($pdo);
            $pdo->commit();
            Response::json(array_merge(['ok' => true], $payload));
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->knownError($e);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->serverError($e);
        }
    }

    /** @param array<int,array<string,mixed>> $items @return array<string,bool> */
    private function capabilityMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[(string)$item['codigo_capacidad']] = !empty($item['efectiva']);
        }
        ksort($map);
        return $map;
    }

    private function knownError(\RuntimeException $e): void
    {
        $status = match ($e->getMessage()) {
            'EMPRESA_NO_ENCONTRADA', 'COMPLEMENTO_NO_ENCONTRADO', 'COMPLEMENTO_ACTIVACION_NO_ENCONTRADA' => 404,
            'COMPLEMENTOS_SCHEMA_MISSING', 'ENTITLEMENT_EXCEPTION_SCHEMA_MISSING' => 503,
            default => 400,
        };
        Response::json(['error' => $e->getMessage()], $status);
    }

    private function serverError(\Throwable $e): void
    {
        $payload = ['error' => 'SERVER_ERROR'];
        if (($_ENV['APP_DEBUG'] ?? '0') === '1') $payload['message'] = $e->getMessage();
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

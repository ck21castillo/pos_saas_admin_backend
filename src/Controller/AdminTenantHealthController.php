<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Response;
use PosAdmin\Service\TenantHealthService;

final class AdminTenantHealthController
{
    public function list(): void
    {
        try {
            Response::json((new TenantHealthService())->list($_GET));
        } catch (\Throwable $e) {
            $payload = ['error' => 'TENANT_HEALTH_FAILED'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }

    public function show(int $idEmpresa): void
    {
        if ($idEmpresa <= 0) {
            Response::json(['error' => 'INVALID_ID'], 400);
        }

        $deep = filter_var($_GET['deep'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($deep === null) {
            $deep = true;
        }

        try {
            Response::json((new TenantHealthService())->show($idEmpresa, $deep));
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'EMPRESA_NOT_FOUND') {
                Response::json(['error' => 'NOT_FOUND'], 404);
            }
            Response::json(['error' => 'TENANT_HEALTH_FAILED', 'message' => $code], 500);
        } catch (\Throwable $e) {
            $payload = ['error' => 'TENANT_HEALTH_FAILED'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }
}

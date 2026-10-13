<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\BusinessCategoryService;
use PosAdmin\Service\BusinessConfigService;
use PosAdmin\Service\AdminTenantSyncService;
use PosAdmin\Service\SaasCapabilityService;

final class AdminEmpresaConfiguracionController
{
    /** GET /admin/empresas/{id}/configuracion-negocio */
    public function show(int $idEmpresa): void
    {
        $pdo = Database::getConnection();
        $empresa = BusinessConfigService::getEmpresa($pdo, $idEmpresa);

        if (!$empresa) {
            Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
        }

        Response::json(array_merge(
            ['ok' => true],
            BusinessConfigService::getConfig($pdo, $idEmpresa)
        ));
    }

    /**
     * PUT /admin/empresas/{id}/configuracion-negocio
     * body: { categoria_negocio: "<categoria comercial activa>", capacidades: { CODE: boolean } }
     */
    public function save(int $idEmpresa, array $body): void
    {
        $actorId = (int)($_REQUEST['adminId'] ?? 0);
        $actorEmail = (string)($_REQUEST['adminEmail'] ?? '');

        $categoriaNegocio = strtoupper(trim((string)($body['categoria_negocio'] ?? '')));

        $capacidades = $this->normalizeCapabilitiesPayload($body['capacidades'] ?? []);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $empresa = BusinessConfigService::getEmpresa($pdo, $idEmpresa);
            if (!$empresa) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
            }

            $category = (new BusinessCategoryService())->resolveActiveForCompany($pdo, $categoriaNegocio);
            $tipoNegocio = BusinessConfigService::normalizeOperationalProfile($category['perfil_operativo']);
            $before = BusinessConfigService::getConfig($pdo, $idEmpresa);
            $validCodes = array_flip(BusinessConfigService::validCapabilityCodes($pdo));
            foreach (array_keys($capacidades) as $code) {
                if (!isset($validCodes[$code])) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "codigo_capacidad invalido: $code"], 422);
                }
                if ((new SaasCapabilityService())->isManaged($code)) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "La capacidad $code se administra desde suscripcion SaaS."], 422);
                }
            }

            $this->assertLotsCanBeDisabled($idEmpresa, $before, $capacidades);
            $this->assertWeightCanBeDisabled($idEmpresa, $before, $capacidades);
            $this->assertPresentationsCanBeDisabled($idEmpresa, $before, $capacidades);
            $this->assertSerialsCanBeDisabled($idEmpresa, $before, $capacidades);

            $updEmpresa = $pdo->prepare('
                UPDATE pos_saas.empresa
                SET categoria_negocio = :categoria, tipo_negocio = :tipo, updated_at = now()
                WHERE id_empresa = :id
            ');
            $updEmpresa->execute([
                ':categoria' => $category['codigo'],
                ':tipo' => $tipoNegocio,
                ':id' => $idEmpresa,
            ]);

            if ($capacidades) {
                $upCap = $pdo->prepare('
                    INSERT INTO pos_saas.empresa_capacidad (id_empresa, codigo_capacidad, enabled)
                    VALUES (:e, :c, :enabled)
                    ON CONFLICT (id_empresa, codigo_capacidad)
                    DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()
                ');

                foreach ($capacidades as $code => $enabled) {
                    $upCap->execute([
                        ':e' => $idEmpresa,
                        ':c' => $code,
                        ':enabled' => $enabled ? 1 : 0,
                    ]);
                }
            }

            $after = BusinessConfigService::getConfig($pdo, $idEmpresa);

            $audit = $pdo->prepare('
              INSERT INTO admin.audit_log
                (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
              VALUES
                (:aid, :aem, :action, :target_type, :target_id, :before::jsonb, :after::jsonb, :ip, :ua)
            ');
            $audit->execute([
                ':aid' => $actorId ?: null,
                ':aem' => $actorEmail ?: null,
                ':action' => 'EMPRESA_CONFIG_NEGOCIO_SAVE',
                ':target_type' => 'empresa',
                ':target_id' => $idEmpresa,
                ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $sync = new AdminTenantSyncService();
            $sync->enqueueBusinessConfig($pdo, $idEmpresa);

            $pdo->commit();
            Response::json(array_merge(['ok' => true, 'sync' => $sync->processBusinessConfigForCompany($idEmpresa)], $after));
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $payload = ['error' => 'SERVER_ERROR'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $payload['message'] = $e->getMessage();
            }
            Response::json($payload, 500);
        }
    }

    /**
     * Lotes no se puede apagar mientras el tenant siga dependiendo de ellos.
     * Los datos historicos se conservan; para retirar la capacidad debe existir
     * antes un proceso explicito que deje los productos y saldos sin lotes.
     *
     * @param array<string,mixed> $before
     * @param array<string,bool> $capacidades
     */
    private function assertLotsCanBeDisabled(int $idEmpresa, array $before, array $capacidades): void
    {
        $code = BusinessConfigService::CAP_LOTES_VENCIMIENTOS;
        $wasEnabled = BusinessConfigService::toBool($before['capacidades'][$code] ?? false);
        $willBeDisabled = array_key_exists($code, $capacidades) && !$capacidades[$code];

        if (!$wasEnabled || !$willBeDisabled) {
            return;
        }

        $tenant = (new AdminTenantSyncService())->tenantConnectionForCompany($idEmpresa);
        if ($tenant === null) {
            throw new \InvalidArgumentException('CAPACIDAD_LOTES_TENANT_NO_DISPONIBLE');
        }

        try {
            $products = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.producto
                WHERE id_empresa = :empresa
                  AND COALESCE(usa_lote, false) = true
            ');
            $products->execute([':empresa' => $idEmpresa]);
            $productsUsingLots = (int)$products->fetchColumn();

            $stock = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.producto_lote
                WHERE id_empresa = :empresa
                  AND COALESCE(stock_lote, 0) <> 0
            ');
            $stock->execute([':empresa' => $idEmpresa]);
            $lotStockRows = (int)$stock->fetchColumn();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('CAPACIDAD_LOTES_VALIDACION_TENANT_FALLIDA');
        }

        if ($productsUsingLots > 0 || $lotStockRows > 0) {
            throw new \InvalidArgumentException('CAPACIDAD_LOTES_EN_USO');
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,bool> $capacidades
     */
    private function assertWeightCanBeDisabled(int $idEmpresa, array $before, array $capacidades): void
    {
        $code = BusinessConfigService::CAP_PRODUCTOS_PESO;
        $wasEnabled = BusinessConfigService::toBool($before['capacidades'][$code] ?? false);
        $willBeDisabled = array_key_exists($code, $capacidades) && !$capacidades[$code];

        if (!$wasEnabled || !$willBeDisabled) {
            return;
        }

        $tenant = (new AdminTenantSyncService())->tenantConnectionForCompany($idEmpresa);
        if ($tenant === null) {
            throw new \InvalidArgumentException('CAPACIDAD_PESO_TENANT_NO_DISPONIBLE');
        }

        try {
            $products = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.producto
                WHERE id_empresa = :empresa
                  AND UPPER(COALESCE(tipo_cantidad, \'UNIDAD\')) = \'PESO\'
            ');
            $products->execute([':empresa' => $idEmpresa]);
            $weightProducts = (int)$products->fetchColumn();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('CAPACIDAD_PESO_VALIDACION_TENANT_FALLIDA');
        }

        if ($weightProducts > 0) {
            throw new \InvalidArgumentException('CAPACIDAD_PESO_EN_USO');
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,bool> $capacidades
     */
    private function assertPresentationsCanBeDisabled(int $idEmpresa, array $before, array $capacidades): void
    {
        $code = BusinessConfigService::CAP_PRODUCTOS_PRESENTACION;
        $wasEnabled = BusinessConfigService::toBool($before['capacidades'][$code] ?? false);
        $willBeDisabled = array_key_exists($code, $capacidades) && !$capacidades[$code];

        if (!$wasEnabled || !$willBeDisabled) {
            return;
        }

        $tenant = (new AdminTenantSyncService())->tenantConnectionForCompany($idEmpresa);
        if ($tenant === null) {
            throw new \InvalidArgumentException('CAPACIDAD_PRESENTACIONES_TENANT_NO_DISPONIBLE');
        }

        try {
            $presentations = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.producto_presentacion
                WHERE id_empresa = :empresa
                  AND COALESCE(estado, 1) = 1
            ');
            $presentations->execute([':empresa' => $idEmpresa]);
            $activePresentations = (int)$presentations->fetchColumn();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('CAPACIDAD_PRESENTACIONES_VALIDACION_TENANT_FALLIDA');
        }

        if ($activePresentations > 0) {
            throw new \InvalidArgumentException('CAPACIDAD_PRESENTACIONES_EN_USO');
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,bool> $capacidades
     */
    private function assertSerialsCanBeDisabled(int $idEmpresa, array $before, array $capacidades): void
    {
        $code = 'SERIALES_EN_VENTA';
        $wasEnabled = BusinessConfigService::toBool($before['capacidades'][$code] ?? false);
        $willBeDisabled = array_key_exists($code, $capacidades) && !$capacidades[$code];

        if (!$wasEnabled || !$willBeDisabled) {
            return;
        }

        $tenant = (new AdminTenantSyncService())->tenantConnectionForCompany($idEmpresa);
        if ($tenant === null) {
            throw new \InvalidArgumentException('CAPACIDAD_SERIALES_TENANT_NO_DISPONIBLE');
        }

        try {
            $products = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.producto
                WHERE id_empresa = :empresa
                  AND COALESCE(requiere_serial_imei, false) = true
            ');
            $products->execute([':empresa' => $idEmpresa]);
            $productsRequiringSerial = (int)$products->fetchColumn();

            $history = $tenant->prepare('
                SELECT COUNT(*)
                FROM pos_saas.venta_detalle_serial
                WHERE id_empresa = :empresa
            ');
            $history->execute([':empresa' => $idEmpresa]);
            $soldSerials = (int)$history->fetchColumn();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('CAPACIDAD_SERIALES_VALIDACION_TENANT_FALLIDA');
        }

        if ($productsRequiringSerial > 0 || $soldSerials > 0) {
            throw new \InvalidArgumentException('CAPACIDAD_SERIALES_EN_USO');
        }
    }

    private function normalizeCapabilitiesPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $code = strtoupper(trim((string)($value['codigo_capacidad'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $normalized[$code] = BusinessConfigService::toBool($value['enabled'] ?? false);
                continue;
            }

            $code = strtoupper(trim((string)$key));
            if ($code === '') {
                continue;
            }
            $normalized[$code] = BusinessConfigService::toBool($value);
        }

        return $normalized;
    }
}

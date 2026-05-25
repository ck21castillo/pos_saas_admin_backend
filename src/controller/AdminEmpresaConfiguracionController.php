<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\BusinessConfigService;

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
     * body: { tipo_negocio: "GENERAL|DROGUERIA|TIENDA_MINIMARKET", capacidades: { CODE: boolean } }
     */
    public function save(int $idEmpresa, array $body): void
    {
        $actorId = (int)($_REQUEST['adminId'] ?? 0);
        $actorEmail = (string)($_REQUEST['adminEmail'] ?? '');

        $rawTipo = strtoupper(trim((string)($body['tipo_negocio'] ?? '')));
        if ($rawTipo !== '' && !in_array($rawTipo, BusinessConfigService::validBusinessTypes(), true)) {
            Response::json(['error' => 'VALIDATION', 'message' => 'tipo_negocio invalido'], 422);
        }

        $capacidades = $this->normalizeCapabilitiesPayload($body['capacidades'] ?? []);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $empresa = BusinessConfigService::getEmpresa($pdo, $idEmpresa);
            if (!$empresa) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
            }

            $tipoNegocio = $rawTipo !== ''
                ? $rawTipo
                : BusinessConfigService::normalizeBusinessType((string)$empresa['tipo_negocio']);
            $before = BusinessConfigService::getConfig($pdo, $idEmpresa);
            $validCodes = array_flip(BusinessConfigService::validCapabilityCodes($pdo));
            foreach (array_keys($capacidades) as $code) {
                if (!isset($validCodes[$code])) {
                    $pdo->rollBack();
                    Response::json(['error' => 'VALIDATION', 'message' => "codigo_capacidad invalido: $code"], 422);
                }
            }

            $updEmpresa = $pdo->prepare('
                UPDATE pos_saas.empresa
                SET tipo_negocio = :tipo, updated_at = now()
                WHERE id_empresa = :id
            ');
            $updEmpresa->execute([':tipo' => $tipoNegocio, ':id' => $idEmpresa]);

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

            $pdo->commit();
            Response::json(array_merge(['ok' => true], $after));
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

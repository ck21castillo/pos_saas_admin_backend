<?php

declare(strict_types=1);

namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;
use PosAdmin\Service\AdminInventoryImportConfirmService;
use PosAdmin\Service\AdminInventoryImportPreviewService;
use PosAdmin\Service\AdminInventoryImportTemplateService;
use PosAdmin\Service\BusinessConfigService;
use RuntimeException;

final class AdminInventoryImportController
{
    /** GET /admin/empresas/{id}/inventario-import/plantilla */
    public function template(int $idEmpresa): void
    {
        $pdo = Database::getConnection();
        $empresa = BusinessConfigService::getEmpresa($pdo, $idEmpresa);

        if (!$empresa) {
            Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
        }

        $config = BusinessConfigService::getConfig($pdo, $idEmpresa);
        $file = (new AdminInventoryImportTemplateService())->buildXlsx($config);

        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $file['content'];
        exit;
    }

    /** POST /admin/empresas/{id}/inventario-import/preview */
    public function preview(int $idEmpresa): void
    {
        try {
            $file = $_FILES['archivo'] ?? ($_FILES['file'] ?? null);
            if (!is_array($file)) {
                Response::json([
                    'ok' => false,
                    'error' => 'FILE_REQUIRED',
                    'message' => 'Debes seleccionar un archivo Excel.',
                ], 400);
            }

            $pdo = Database::getConnection();
            $result = (new AdminInventoryImportPreviewService())->preview($pdo, $idEmpresa, $file);
            Response::json($result, ($result['ok'] ?? false) ? 200 : 404);
        } catch (RuntimeException $e) {
            Response::json([
                'ok' => false,
                'error' => 'INVALID_FILE',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /** POST /admin/empresas/{id}/inventario-import/confirm */
    public function confirm(int $idEmpresa): void
    {
        try {
            $file = $_FILES['archivo'] ?? ($_FILES['file'] ?? null);
            if (!is_array($file)) {
                Response::json([
                    'ok' => false,
                    'error' => 'FILE_REQUIRED',
                    'message' => 'Debes seleccionar un archivo Excel.',
                ], 400);
            }

            $pdo = Database::getConnection();
            $result = (new AdminInventoryImportConfirmService())->confirm($pdo, $idEmpresa, $file);
            Response::json($result, ($result['ok'] ?? false) ? 200 : 422);
        } catch (RuntimeException $e) {
            Response::json([
                'ok' => false,
                'error' => 'IMPORT_CONFIRM_FAILED',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

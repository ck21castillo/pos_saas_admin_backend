<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminEmpresaUsuarioController
{
    /** GET /admin/empresas/{id}/usuarios */
    public function list(int $idEmpresa): void
    {
        $pdo = Database::getConnection();

        $chk = $pdo->prepare("SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :id");
        $chk->execute([':id' => $idEmpresa]);
        if (!$chk->fetchColumn()) {
            Response::json(['error' => 'NOT_FOUND', 'message' => 'Empresa no existe'], 404);
        }

        $st = $pdo->prepare("
          SELECT
            u.id_usuario,
            u.id_empresa,
            u.nombre,
            u.apellido,
            u.email,
            u.documento,
            u.telefono,
            u.direccion,
            u.estado,
            u.created_at,
            u.updated_at
          FROM pos_saas.usuario u
          WHERE u.id_empresa = :eid
          ORDER BY u.created_at DESC, u.id_usuario DESC
        ");
        $st->execute([':eid' => $idEmpresa]);
        $items = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        Response::json([
            'ok' => true,
            'id_empresa' => $idEmpresa,
            'items' => $items,
        ]);
    }
}


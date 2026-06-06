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

    /** GET /admin/empresas/{id}/usuario-admin */
    public function adminUser(int $idEmpresa): void
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
            u.estado
          FROM pos_saas.usuario u
          WHERE u.id_empresa = :eid
            AND u.estado = 1
            AND (
              u.rol = 1
              OR EXISTS (
                SELECT 1
                FROM pos_saas.usuario_rol ur
                JOIN pos_saas.rol r ON r.id_rol = ur.id_rol
                WHERE ur.id_usuario = u.id_usuario
                  AND ur.id_empresa = :eid
                  AND UPPER(r.nombre) = 'ADMINISTRATIVO'
              )
            )
          ORDER BY u.created_at ASC, u.id_usuario ASC
          LIMIT 1
        ");
        $st->execute([':eid' => $idEmpresa]);
        $item = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$item) {
            Response::json([
                'error' => 'ADMIN_USUARIO_NO_ENCONTRADO',
                'message' => 'No hay usuario administrador activo para esta empresa',
            ], 404);
        }

        Response::json([
            'ok' => true,
            'id_empresa' => $idEmpresa,
            'item' => $item,
        ]);
    }
}

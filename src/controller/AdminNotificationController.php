<?php

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminNotificationController
{
    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** GET /admin/notifications */
    public function list(): void
    {
        $scope = strtoupper(trim((string)($_GET['scope'] ?? '')));
        $estado = trim((string)($_GET['estado'] ?? ''));
        $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = [];
        $params = [];

        if ($scope !== '') {
            $where[] = 'n.scope = :scope';
            $params[':scope'] = $scope;
        }
        if ($estado !== '' && in_array($estado, ['0', '1'], true)) {
            $where[] = 'n.estado = :estado';
            $params[':estado'] = (int)$estado;
        }
        if ($idEmpresa > 0) {
            $where[] = 'n.id_empresa = :empresa';
            $params[':empresa'] = $idEmpresa;
        }
        if ($q !== '') {
            $where[] = '(n.titulo ILIKE :q OR n.mensaje ILIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $sql = '
            SELECT
                n.id_notification,
                n.scope,
                n.id_empresa,
                e.nombre AS empresa_nombre,
                n.id_usuario,
                u.email AS usuario_email,
                n.titulo,
                n.mensaje,
                n.tipo,
                n.meta,
                n.starts_at,
                n.expires_at,
                n.created_by,
                n.estado,
                n.created_at
            FROM admin.notification n
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = n.id_empresa
            LEFT JOIN pos_saas.usuario u ON u.id_usuario = n.id_usuario
        ';

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset';

        $pdo = Database::getConnection();
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        Response::json([
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** POST /admin/notifications */
    public function create(): void
    {
        $b = $this->jsonBody();

        $scope = strtoupper(trim((string)($b['scope'] ?? '')));
        $idEmpresa = (int)($b['id_empresa'] ?? 0);
        $idUsuario = (int)($b['id_usuario'] ?? 0);
        $titulo = trim((string)($b['titulo'] ?? ''));
        $mensaje = trim((string)($b['mensaje'] ?? ''));
        $tipo = strtoupper(trim((string)($b['tipo'] ?? 'INFO')));
        $startsAt = trim((string)($b['starts_at'] ?? ''));
        $expiresAt = trim((string)($b['expires_at'] ?? ''));
        $meta = $b['meta'] ?? null;

        if (!in_array($scope, ['GLOBAL', 'EMPRESA', 'USUARIO'], true)) {
            Response::json(['error' => 'SCOPE_INVALIDO'], 422);
        }
        if ($titulo === '' || $mensaje === '') {
            Response::json(['error' => 'TITULO_Y_MENSAJE_REQUERIDOS'], 422);
        }
        if (!in_array($tipo, ['INFO', 'SUCCESS', 'WARNING', 'ERROR'], true)) {
            $tipo = 'INFO';
        }

        if ($scope === 'GLOBAL') {
            $idEmpresa = 0;
            $idUsuario = 0;
        } elseif ($scope === 'EMPRESA') {
            if ($idEmpresa <= 0) {
                Response::json(['error' => 'ID_EMPRESA_REQUERIDO'], 422);
            }
            $idUsuario = 0;
        } else { // USUARIO
            if ($idEmpresa <= 0 || $idUsuario <= 0) {
                Response::json(['error' => 'ID_EMPRESA_E_ID_USUARIO_REQUERIDOS'], 422);
            }
        }

        $adminId = (int)($_REQUEST['adminId'] ?? 0);

        $pdo = Database::getConnection();

        if ($scope !== 'GLOBAL') {
            $vEmp = $pdo->prepare('SELECT 1 FROM pos_saas.empresa WHERE id_empresa = :e LIMIT 1');
            $vEmp->execute([':e' => $idEmpresa]);
            if (!(bool)$vEmp->fetchColumn()) {
                Response::json(['error' => 'EMPRESA_NO_EXISTE'], 404);
            }
        }

        if ($scope === 'USUARIO') {
            $vUsr = $pdo->prepare('
                SELECT 1
                FROM pos_saas.usuario
                WHERE id_usuario = :u AND id_empresa = :e
                LIMIT 1
            ');
            $vUsr->execute([':u' => $idUsuario, ':e' => $idEmpresa]);
            if (!(bool)$vUsr->fetchColumn()) {
                Response::json(['error' => 'USUARIO_NO_PERTENECE_EMPRESA'], 422);
            }
        }

        $metaJson = null;
        if ($meta !== null) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                Response::json(['error' => 'META_INVALIDA'], 422);
            }
        }

        $ins = $pdo->prepare('
            INSERT INTO admin.notification
                (scope, id_empresa, id_usuario, titulo, mensaje, tipo, meta, starts_at, expires_at, created_by, estado)
            VALUES
                (:scope, :empresa, :usuario, :titulo, :mensaje, :tipo, :meta::jsonb, :starts_at, :expires_at, :created_by, 1)
            RETURNING id_notification
        ');
        $ins->execute([
            ':scope' => $scope,
            ':empresa' => $idEmpresa > 0 ? $idEmpresa : null,
            ':usuario' => $idUsuario > 0 ? $idUsuario : null,
            ':titulo' => $titulo,
            ':mensaje' => $mensaje,
            ':tipo' => $tipo,
            ':meta' => $metaJson,
            ':starts_at' => $startsAt !== '' ? $startsAt : null,
            ':expires_at' => $expiresAt !== '' ? $expiresAt : null,
            ':created_by' => $adminId > 0 ? $adminId : null,
        ]);

        Response::json([
            'ok' => true,
            'id_notification' => (int)$ins->fetchColumn(),
        ], 201);
    }

    /** PATCH /admin/notifications/{id}/estado */
    public function setEstado(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $b = $this->jsonBody();
        $estado = (int)($b['estado'] ?? -1);

        if ($id <= 0 || !in_array($estado, [0, 1], true)) {
            Response::json(['error' => 'PAYLOAD_INVALIDO'], 422);
        }

        $pdo = Database::getConnection();
        $st = $pdo->prepare('
            UPDATE admin.notification
            SET estado = :estado
            WHERE id_notification = :id
        ');
        $st->execute([':estado' => $estado, ':id' => $id]);

        if ($st->rowCount() === 0) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        Response::json(['ok' => true, 'id_notification' => $id, 'estado' => $estado]);
    }
}

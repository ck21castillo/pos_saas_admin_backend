<?php

namespace PosAdmin\Controller;

use PDO;
use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class AdminNotificationController
{
    private const SCOPES = ['GLOBAL', 'EMPRESA', 'USUARIO'];
    private const TIPOS = ['INFO', 'SUCCESS', 'WARNING', 'ERROR'];
    private const SITUACIONES = ['VIGENTE', 'PROGRAMADA', 'EXPIRADA', 'INACTIVA', 'ARCHIVADA'];

    private function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $st = $pdo->prepare('
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = :schema
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ');
        $st->execute([
            ':schema' => $schema,
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$st->fetchColumn();
    }

    private function archiveEnabled(PDO $pdo): bool
    {
        return $this->columnExists($pdo, 'admin', 'notification', 'archived_at');
    }

    private function resolveEmpresaAdminUserId(PDO $pdo, int $idEmpresa): ?int
    {
        $q = $pdo->prepare('
            SELECT u.id_usuario
            FROM pos_saas.usuario u
            WHERE u.id_empresa = :e
              AND u.estado = 1
              AND (
                u.rol = 1
                OR EXISTS (
                  SELECT 1
                  FROM pos_saas.usuario_rol ur
                  JOIN pos_saas.rol r ON r.id_rol = ur.id_rol
                  WHERE ur.id_usuario = u.id_usuario
                    AND ur.id_empresa = :e
                    AND UPPER(r.nombre) = \'ADMINISTRATIVO\'
                )
              )
            ORDER BY u.created_at ASC, u.id_usuario ASC
            LIMIT 1
        ');
        $q->execute([':e' => $idEmpresa]);
        $id = $q->fetchColumn();
        return $id ? (int)$id : null;
    }

    /** GET /admin/notifications */
    public function list(): void
    {
        $scope = strtoupper(trim((string)($_GET['scope'] ?? '')));
        $estado = trim((string)($_GET['estado'] ?? ''));
        $situacion = strtoupper(trim((string)($_GET['situacion'] ?? '')));
        $idEmpresa = (int)($_GET['id_empresa'] ?? 0);
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        if ($scope !== '' && !in_array($scope, self::SCOPES, true)) {
            Response::json(['error' => 'SCOPE_INVALIDO'], 422);
        }
        if ($situacion !== '' && !in_array($situacion, self::SITUACIONES, true)) {
            Response::json(['error' => 'SITUACION_INVALIDA'], 422);
        }

        $pdo = Database::getConnection();
        $hasArchive = $this->archiveEnabled($pdo);

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
            $where[] = '(n.titulo ILIKE :q OR n.mensaje ILIKE :q OR CAST(n.id_notification AS text) = :q_exact)';
            $params[':q'] = '%' . $q . '%';
            $params[':q_exact'] = $q;
        }

        if ($hasArchive) {
            if ($situacion === 'ARCHIVADA') {
                $where[] = 'n.archived_at IS NOT NULL';
            } else {
                $where[] = 'n.archived_at IS NULL';
            }
        }

        if ($situacion === 'VIGENTE') {
            $where[] = 'n.estado = 1';
            $where[] = '(n.starts_at IS NULL OR n.starts_at <= now())';
            $where[] = '(n.expires_at IS NULL OR n.expires_at > now())';
        } elseif ($situacion === 'PROGRAMADA') {
            $where[] = 'n.estado = 1';
            $where[] = 'n.starts_at IS NOT NULL AND n.starts_at > now()';
        } elseif ($situacion === 'EXPIRADA') {
            $where[] = 'n.expires_at IS NOT NULL AND n.expires_at <= now()';
        } elseif ($situacion === 'INACTIVA') {
            $where[] = 'n.estado = 0';
        }

        $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
        $archiveSelect = $hasArchive
            ? 'n.archived_at, n.archived_by,'
            : 'NULL::timestamptz AS archived_at, NULL::bigint AS archived_by,';
        $estadoOperativo = $hasArchive
            ? "CASE
                WHEN n.archived_at IS NOT NULL THEN 'ARCHIVADA'
                WHEN n.estado = 0 THEN 'INACTIVA'
                WHEN n.starts_at IS NOT NULL AND n.starts_at > now() THEN 'PROGRAMADA'
                WHEN n.expires_at IS NOT NULL AND n.expires_at <= now() THEN 'EXPIRADA'
                ELSE 'VIGENTE'
              END AS estado_operativo,"
            : "CASE
                WHEN n.estado = 0 THEN 'INACTIVA'
                WHEN n.starts_at IS NOT NULL AND n.starts_at > now() THEN 'PROGRAMADA'
                WHEN n.expires_at IS NOT NULL AND n.expires_at <= now() THEN 'EXPIRADA'
                ELSE 'VIGENTE'
              END AS estado_operativo,";

        $count = $pdo->prepare('SELECT COUNT(*) FROM admin.notification n' . $whereSql);
        foreach ($params as $k => $v) {
            $count->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int)$count->fetchColumn();

        $sql = '
            SELECT
                n.id_notification,
                n.scope,
                n.id_empresa,
                e.nombre AS empresa_nombre,
                n.id_usuario,
                u.email AS usuario_email,
                TRIM(COALESCE(u.nombre, \'\') || \' \' || COALESCE(u.apellido, \'\')) AS usuario_nombre,
                n.titulo,
                n.mensaje,
                n.tipo,
                n.meta,
                n.starts_at,
                n.expires_at,
                ' . $archiveSelect . '
                ' . $estadoOperativo . '
                COALESCE(nr.read_count, 0)::int AS read_count,
                nr.last_read_at,
                CASE
                    WHEN n.scope = \'GLOBAL\' THEN \'Todas las empresas\'
                    WHEN n.scope = \'EMPRESA\' THEN COALESCE(e.nombre, \'Empresa \' || n.id_empresa::text)
                    WHEN n.scope = \'USUARIO\' THEN COALESCE(NULLIF(TRIM(COALESCE(u.nombre, \'\') || \' \' || COALESCE(u.apellido, \'\')), \'\'), u.email, \'Usuario \' || n.id_usuario::text)
                    ELSE n.scope
                END AS target_label,
                n.created_by,
                n.estado,
                n.created_at
            FROM admin.notification n
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = n.id_empresa
            LEFT JOIN pos_saas.usuario u ON u.id_empresa = n.id_empresa AND u.id_usuario = n.id_usuario
            LEFT JOIN (
                SELECT id_notification, COUNT(*) AS read_count, MAX(read_at) AS last_read_at
                FROM admin.notification_read
                GROUP BY id_notification
            ) nr ON nr.id_notification = n.id_notification
        ' . $whereSql . '
            ORDER BY n.created_at DESC, n.id_notification DESC
            LIMIT :limit OFFSET :offset
        ';

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        Response::json([
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'archive_enabled' => $hasArchive,
        ]);
    }

    /** GET /admin/notifications/{id}/reads */
    public function reads(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'ID_INVALIDO'], 422);
        }

        $pdo = Database::getConnection();
        $notification = $pdo->prepare('
            SELECT
                n.id_notification, n.scope, n.id_empresa, e.nombre AS empresa_nombre,
                n.id_usuario, u.email AS usuario_email,
                TRIM(COALESCE(u.nombre, \'\') || \' \' || COALESCE(u.apellido, \'\')) AS usuario_nombre,
                n.titulo, n.mensaje, n.tipo, n.estado, n.starts_at, n.expires_at, n.created_at
            FROM admin.notification n
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = n.id_empresa
            LEFT JOIN pos_saas.usuario u ON u.id_empresa = n.id_empresa AND u.id_usuario = n.id_usuario
            WHERE n.id_notification = :id
            LIMIT 1
        ');
        $notification->execute([':id' => $id]);
        $item = $notification->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        $reads = $pdo->prepare('
            SELECT
                r.id_empresa,
                e.nombre AS empresa_nombre,
                r.id_usuario,
                u.email AS usuario_email,
                TRIM(COALESCE(u.nombre, \'\') || \' \' || COALESCE(u.apellido, \'\')) AS usuario_nombre,
                r.read_at
            FROM admin.notification_read r
            LEFT JOIN pos_saas.empresa e ON e.id_empresa = r.id_empresa
            LEFT JOIN pos_saas.usuario u ON u.id_empresa = r.id_empresa AND u.id_usuario = r.id_usuario
            WHERE r.id_notification = :id
            ORDER BY r.read_at DESC
            LIMIT 100
        ');
        $reads->execute([':id' => $id]);
        $rows = $reads->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Response::json([
            'notification' => $item,
            'reads' => $rows,
            'read_count' => count($rows),
            'limit' => 100,
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

        if (!in_array($scope, self::SCOPES, true)) {
            Response::json(['error' => 'SCOPE_INVALIDO'], 422);
        }
        if ($titulo === '' || $mensaje === '') {
            Response::json(['error' => 'TITULO_Y_MENSAJE_REQUERIDOS'], 422);
        }
        if (!in_array($tipo, self::TIPOS, true)) {
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
        } else {
            if ($idEmpresa <= 0) {
                Response::json(['error' => 'ID_EMPRESA_REQUERIDO'], 422);
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
            if ($idUsuario <= 0) {
                $idUsuario = $this->resolveEmpresaAdminUserId($pdo, $idEmpresa) ?? 0;
            }
            if ($idUsuario <= 0) {
                Response::json(['error' => 'ADMIN_USUARIO_NO_ENCONTRADO'], 422);
            }

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
                (:scope, :empresa, :usuario, :titulo, :mensaje, :tipo, :meta::jsonb, NULLIF(:starts_at, \'\')::timestamptz, NULLIF(:expires_at, \'\')::timestamptz, :created_by, 1)
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
            ':starts_at' => $startsAt,
            ':expires_at' => $expiresAt,
            ':created_by' => $adminId > 0 ? $adminId : null,
        ]);

        Response::json([
            'ok' => true,
            'id_notification' => (int)$ins->fetchColumn(),
        ], 201);
    }

    /** PATCH /admin/notifications/{id} */
    public function update(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'ID_INVALIDO'], 422);
        }

        $b = $this->jsonBody();

        $titulo = array_key_exists('titulo', $b) ? trim((string)$b['titulo']) : null;
        $mensaje = array_key_exists('mensaje', $b) ? trim((string)$b['mensaje']) : null;
        $tipo = array_key_exists('tipo', $b) ? strtoupper(trim((string)$b['tipo'])) : null;
        $startsAt = array_key_exists('starts_at', $b) ? trim((string)$b['starts_at']) : null;
        $expiresAt = array_key_exists('expires_at', $b) ? trim((string)$b['expires_at']) : null;

        if ($titulo !== null && $titulo === '') {
            Response::json(['error' => 'TITULO_REQUERIDO'], 422);
        }
        if ($mensaje !== null && $mensaje === '') {
            Response::json(['error' => 'MENSAJE_REQUERIDO'], 422);
        }
        if ($tipo !== null && !in_array($tipo, self::TIPOS, true)) {
            Response::json(['error' => 'TIPO_INVALIDO'], 422);
        }

        $sets = [];
        $paramsSql = [':id' => $id];

        if ($titulo !== null) {
            $sets[] = 'titulo = :titulo';
            $paramsSql[':titulo'] = $titulo;
        }
        if ($mensaje !== null) {
            $sets[] = 'mensaje = :mensaje';
            $paramsSql[':mensaje'] = $mensaje;
        }
        if ($tipo !== null) {
            $sets[] = 'tipo = :tipo';
            $paramsSql[':tipo'] = $tipo;
        }
        if ($startsAt !== null) {
            $sets[] = 'starts_at = NULLIF(:starts_at, \'\')::timestamptz';
            $paramsSql[':starts_at'] = $startsAt;
        }
        if ($expiresAt !== null) {
            $sets[] = 'expires_at = NULLIF(:expires_at, \'\')::timestamptz';
            $paramsSql[':expires_at'] = $expiresAt;
        }

        if (empty($sets)) {
            Response::json(['error' => 'SIN_CAMBIOS'], 422);
        }

        $pdo = Database::getConnection();
        $sql = 'UPDATE admin.notification SET ' . implode(', ', $sets) . ' WHERE id_notification = :id';
        $st = $pdo->prepare($sql);
        $st->execute($paramsSql);

        if ($st->rowCount() === 0) {
            $chk = $pdo->prepare('SELECT 1 FROM admin.notification WHERE id_notification = :id');
            $chk->execute([':id' => $id]);
            if (!(bool)$chk->fetchColumn()) {
                Response::json(['error' => 'NOT_FOUND'], 404);
            }
        }

        Response::json(['ok' => true, 'id_notification' => $id]);
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

        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare('
                SELECT estado
                FROM admin.notification
                WHERE id_notification = :id
                FOR UPDATE
            ');
            $sel->execute([':id' => $id]);
            $currentRaw = $sel->fetchColumn();
            if ($currentRaw === false) {
                $pdo->rollBack();
                Response::json(['error' => 'NOT_FOUND'], 404);
            }
            $currentEstado = (int)$currentRaw;

            $upd = $pdo->prepare('
                UPDATE admin.notification
                SET estado = :estado
                WHERE id_notification = :id
            ');
            $upd->execute([':estado' => $estado, ':id' => $id]);

            $resetReads = 0;
            if ($currentEstado === 0 && $estado === 1) {
                $del = $pdo->prepare('
                    DELETE FROM admin.notification_read
                    WHERE id_notification = :id
                ');
                $del->execute([':id' => $id]);
                $resetReads = $del->rowCount();
            }

            $pdo->commit();
            Response::json([
                'ok' => true,
                'id_notification' => $id,
                'estado' => $estado,
                'reset_reads' => $resetReads,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::json(['error' => 'ERROR_SET_ESTADO'], 500);
        }
    }

    /** PATCH /admin/notifications/{id}/archive */
    public function archive(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $body = $this->jsonBody();
        $archived = array_key_exists('archived', $body) ? (bool)$body['archived'] : true;
        $adminId = (int)($_REQUEST['adminId'] ?? 0);

        if ($id <= 0) {
            Response::json(['error' => 'ID_INVALIDO'], 422);
        }

        $pdo = Database::getConnection();
        if (!$this->archiveEnabled($pdo)) {
            Response::json(['error' => 'NOTIFICATION_ARCHIVE_NOT_READY'], 409);
        }

        $sql = $archived
            ? 'UPDATE admin.notification SET archived_at = now(), archived_by = :admin WHERE id_notification = :id'
            : 'UPDATE admin.notification SET archived_at = NULL, archived_by = NULL WHERE id_notification = :id';
        $st = $pdo->prepare($sql);
        $params = [':id' => $id];
        if ($archived) {
            $params[':admin'] = $adminId > 0 ? $adminId : null;
        }
        $st->execute($params);

        if ($st->rowCount() === 0) {
            Response::json(['error' => 'NOT_FOUND'], 404);
        }

        Response::json(['ok' => true, 'id_notification' => $id, 'archived' => $archived]);
    }

    /** POST /admin/notifications/archive-expired */
    public function archiveExpired(): void
    {
        $adminId = (int)($_REQUEST['adminId'] ?? 0);
        $pdo = Database::getConnection();
        if (!$this->archiveEnabled($pdo)) {
            Response::json(['error' => 'NOTIFICATION_ARCHIVE_NOT_READY'], 409);
        }

        $st = $pdo->prepare('
            UPDATE admin.notification
            SET archived_at = now(),
                archived_by = :admin
            WHERE archived_at IS NULL
              AND expires_at IS NOT NULL
              AND expires_at <= now()
        ');
        $st->execute([':admin' => $adminId > 0 ? $adminId : null]);

        Response::json(['ok' => true, 'archived' => $st->rowCount()]);
    }
}

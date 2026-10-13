<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use InvalidArgumentException;
use PDO;

final class BusinessCategoryService
{
    /**
     * Capacidades que una categoria puede sugerir al crear una empresa.
     * La sugerencia no habilita una capacidad efectiva ni modifica un plan.
     *
     * @var list<string>
     */
    public const INITIAL_SUGGESTION_CAPABILITY_CODES = [
        'LOTES_VENCIMIENTOS',
        'PRODUCTOS_PESO',
        'PRODUCTOS_PRESENTACION',
        'SERIALES_EN_VENTA',
    ];

    /** @return list<string> */
    public static function validOperationalProfiles(): array
    {
        return BusinessConfigService::validOperationalProfiles();
    }

    /** @return list<array{codigo:string,nombre:string,descripcion:string}> */
    public static function operationalProfilesCatalog(): array
    {
        $metadata = [
            BusinessConfigService::TYPE_GENERAL => [
                'nombre' => 'General',
                'descripcion' => 'Operacion comercial general sin reglas sectoriales adicionales.',
            ],
            BusinessConfigService::TYPE_DROGUERIA => [
                'nombre' => 'Drogueria',
                'descripcion' => 'Operacion orientada a droguerias y sus controles de inventario.',
            ],
            BusinessConfigService::TYPE_TIENDA_MINIMARKET => [
                'nombre' => 'Tienda / minimarket',
                'descripcion' => 'Operacion para tiendas, minimarkets y venta de consumo diario.',
            ],
            BusinessConfigService::TYPE_TECNOLOGIA_SERVICIO_TECNICO => [
                'nombre' => 'Tecnologia y servicio tecnico',
                'descripcion' => 'Operacion para tecnologia, diagnostico, reparacion y postventa.',
            ],
        ];

        $profiles = [];
        foreach (BusinessConfigService::validOperationalProfiles() as $code) {
            $profiles[] = [
                'codigo' => $code,
                'nombre' => $metadata[$code]['nombre'],
                'descripcion' => $metadata[$code]['descripcion'],
            ];
        }

        return $profiles;
    }

    /** @return array{perfiles_operativos:list<array{codigo:string,nombre:string,descripcion:string}>,capacidades:list<array{codigo_capacidad:string,nombre:string,descripcion:?string,activo:bool}>} */
    public function presentationCatalogs(PDO $pdo): array
    {
        $st = $pdo->prepare('
            SELECT codigo_capacidad, nombre, descripcion, estado
            FROM pos_saas.capacidad
            WHERE estado = 1
              AND codigo_capacidad = ANY(CAST(:codes AS text[]))
            ORDER BY codigo_capacidad ASC
        ');
        $st->execute([
            ':codes' => '{' . implode(',', self::INITIAL_SUGGESTION_CAPABILITY_CODES) . '}',
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'perfiles_operativos' => self::operationalProfilesCatalog(),
            'capacidades' => array_map(static function (array $row): array {
                return [
                    'codigo_capacidad' => (string)$row['codigo_capacidad'],
                    'nombre' => (string)$row['nombre'],
                    'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
                    'activo' => self::toBool($row['estado'] ?? false),
                ];
            }, $rows),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(PDO $pdo): array
    {
        $rows = $pdo->query('
            SELECT
                c.id_categoria_negocio,
                c.codigo,
                c.nombre,
                c.descripcion,
                c.perfil_operativo,
                c.activo,
                c.visible_publico,
                c.orden,
                cc.codigo_capacidad,
                cc.habilitada_por_defecto
            FROM admin.saas_categoria_negocio c
            LEFT JOIN admin.saas_categoria_negocio_capacidad cc
              ON cc.id_categoria_negocio = c.id_categoria_negocio
            ORDER BY c.orden ASC, c.codigo ASC, cc.codigo_capacidad ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int)$row['id_categoria_negocio'];
            if (!isset($items[$id])) {
                $items[$id] = $this->normalizeCategory($row);
                $items[$id]['capacidades_iniciales'] = [];
            }
            if (($row['codigo_capacidad'] ?? null) !== null) {
                $items[$id]['capacidades_iniciales'][] = [
                    'codigo_capacidad' => (string)$row['codigo_capacidad'],
                    'habilitada_por_defecto' => self::toBool($row['habilitada_por_defecto'] ?? false),
                ];
            }
        }

        return array_values($items);
    }

    /** @return list<array{codigo:string,nombre:string,descripcion:?string,perfil_operativo:string,activo:bool,visible_publico:bool,orden:int}> */
    public function listActiveForCompany(PDO $pdo, ?string $selectedCode = null): array
    {
        $selectedCode = self::normalizeCode((string)$selectedCode);
        $st = $pdo->prepare(' 
            SELECT codigo, nombre, descripcion, perfil_operativo, activo, visible_publico, orden
            FROM admin.saas_categoria_negocio
            WHERE activo = true OR codigo = :selected_code
            ORDER BY orden ASC, codigo ASC
        ');
        $st->execute([':selected_code' => $selectedCode]);

        return array_map(static fn (array $row): array => [
            'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
            'perfil_operativo' => (string)$row['perfil_operativo'],
            'activo' => self::toBool($row['activo'] ?? false),
            'visible_publico' => self::toBool($row['visible_publico'] ?? false),
            'orden' => (int)$row['orden'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array{codigo:string,nombre:string,descripcion:?string,perfil_operativo:string} */
    public function resolveActiveForCompany(PDO $pdo, string $categoryCode): array
    {
        $code = self::normalizeCode($categoryCode);
        if ($code === '') {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_REQUERIDA');
        }

        $st = $pdo->prepare(' 
            SELECT codigo, nombre, descripcion, perfil_operativo
            FROM admin.saas_categoria_negocio
            WHERE codigo = :codigo
              AND activo = true
            LIMIT 1
        ');
        $st->execute([':codigo' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_INVALIDA');
        }

        return [
            'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
            'perfil_operativo' => (string)$row['perfil_operativo'],
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(PDO $pdo, array $body, int $actorId, string $actorEmail): array
    {
        $data = $this->normalizeCreatePayload($body);
        $exists = $pdo->prepare('SELECT 1 FROM admin.saas_categoria_negocio WHERE codigo = :codigo LIMIT 1');
        $exists->execute([':codigo' => $data['codigo']]);
        if ($exists->fetchColumn()) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CODIGO_DUPLICADO');
        }

        $insert = $pdo->prepare('
            INSERT INTO admin.saas_categoria_negocio
                (codigo, nombre, descripcion, perfil_operativo, activo, visible_publico, orden)
            VALUES
                (:codigo, :nombre, :descripcion, :perfil_operativo, :activo, :visible_publico, :orden)
            RETURNING id_categoria_negocio
        ');
        $insert->execute($this->statementParams($data));
        $id = (int)$insert->fetchColumn();
        $this->replaceInitialCapabilities($pdo, $id, $data['capacidades_iniciales']);

        $after = $this->find($pdo, $id);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_CATEGORIA_NEGOCIO_CREATE', $id, null, $after);
        return $after;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(PDO $pdo, int $idCategory, array $body, int $actorId, string $actorEmail): array
    {
        $before = $this->find($pdo, $idCategory);
        if (array_key_exists('codigo', $body)
            && self::normalizeCode((string)$body['codigo']) !== (string)$before['codigo']) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CODIGO_NO_EDITABLE');
        }

        $data = $this->normalizeUpdatePayload($body, $before);
        $update = $pdo->prepare('
            UPDATE admin.saas_categoria_negocio
            SET nombre = :nombre,
                descripcion = :descripcion,
                perfil_operativo = :perfil_operativo,
                activo = :activo,
                visible_publico = :visible_publico,
                orden = :orden,
                updated_at = now()
            WHERE id_categoria_negocio = :id
        ');
        $update->execute($this->statementParams($data) + [':id' => $idCategory]);
        if ($data['capacidades_presentes']) {
            $this->replaceInitialCapabilities($pdo, $idCategory, $data['capacidades_iniciales']);
        }

        $after = $this->find($pdo, $idCategory);
        $this->audit($pdo, $actorId, $actorEmail, 'SAAS_CATEGORIA_NEGOCIO_UPDATE', $idCategory, $before, $after);
        return $after;
    }

    /** @return array<string,mixed> */
    private function find(PDO $pdo, int $idCategory): array
    {
        foreach ($this->list($pdo) as $item) {
            if ((int)$item['id_categoria_negocio'] === $idCategory) {
                return $item;
            }
        }
        throw new \RuntimeException('CATEGORIA_NEGOCIO_NO_ENCONTRADA');
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function normalizeCreatePayload(array $body): array
    {
        $code = self::normalizeCode((string)($body['codigo'] ?? ''));
        self::assertCode($code);

        return $this->normalizeFields($body, [
            'codigo' => $code,
            'nombre' => '',
            'descripcion' => null,
            'perfil_operativo' => BusinessConfigService::TYPE_GENERAL,
            'activo' => true,
            'visible_publico' => true,
            'orden' => 100,
            'capacidades_iniciales' => [],
        ], true);
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $before @return array<string,mixed> */
    private function normalizeUpdatePayload(array $body, array $before): array
    {
        return $this->normalizeFields($body, $before, false);
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $defaults @return array<string,mixed> */
    private function normalizeFields(array $body, array $defaults, bool $creating): array
    {
        $nombre = trim((string)($body['nombre'] ?? $defaults['nombre']));
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_NOMBRE_INVALIDO');
        }

        $descripcion = array_key_exists('descripcion', $body)
            ? self::nullableText($body['descripcion'])
            : ($defaults['descripcion'] ?? null);
        if ($descripcion !== null && mb_strlen($descripcion) > 2000) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_DESCRIPCION_INVALIDA');
        }

        $perfil = strtoupper(trim((string)($body['perfil_operativo'] ?? $defaults['perfil_operativo'])));
        if (!in_array($perfil, self::validOperationalProfiles(), true)) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_PERFIL_OPERATIVO_INVALIDO');
        }

        $capacidadesPresentes = array_key_exists('capacidades_iniciales', $body);
        $capacidades = $capacidadesPresentes
            ? self::normalizeCapabilities($body['capacidades_iniciales'])
            : ($defaults['capacidades_iniciales'] ?? []);
        return [
            'codigo' => (string)($defaults['codigo'] ?? ''),
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'perfil_operativo' => $perfil,
            'activo' => self::toBool($body['activo'] ?? $defaults['activo']) ? 1 : 0,
            'visible_publico' => self::toBool($body['visible_publico'] ?? $defaults['visible_publico']) ? 1 : 0,
            'orden' => self::normalizeOrder($body['orden'] ?? $defaults['orden']),
            'capacidades_iniciales' => $capacidades,
            'capacidades_presentes' => $creating || $capacidadesPresentes,
        ];
    }

    /** @param list<array{codigo_capacidad:string,habilitada_por_defecto:bool}> $capabilities */
    private function replaceInitialCapabilities(PDO $pdo, int $idCategory, array $capabilities): void
    {
        $codes = array_column($capabilities, 'codigo_capacidad');
        if ($codes !== []) {
            $placeholders = implode(', ', array_fill(0, count($codes), '?'));
            $valid = $pdo->prepare("SELECT codigo_capacidad FROM pos_saas.capacidad WHERE estado = 1 AND codigo_capacidad IN ({$placeholders})");
            $valid->execute($codes);
            $found = array_fill_keys(array_map('strval', $valid->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
            foreach ($codes as $code) {
                if (!isset($found[$code])) {
                    throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CAPACIDAD_INVALIDA');
                }
            }
        }

        $delete = $pdo->prepare('DELETE FROM admin.saas_categoria_negocio_capacidad WHERE id_categoria_negocio = :id');
        $delete->execute([':id' => $idCategory]);
        if ($capabilities === []) {
            return;
        }

        $insert = $pdo->prepare('
            INSERT INTO admin.saas_categoria_negocio_capacidad
                (id_categoria_negocio, codigo_capacidad, habilitada_por_defecto)
            VALUES (:id, :codigo, :habilitada)
        ');
        foreach ($capabilities as $capability) {
            $insert->execute([
                ':id' => $idCategory,
                ':codigo' => $capability['codigo_capacidad'],
                ':habilitada' => $capability['habilitada_por_defecto'] ? 1 : 0,
            ]);
        }
    }

    /** @param mixed $payload @return list<array{codigo_capacidad:string,habilitada_por_defecto:bool}> */
    private static function normalizeCapabilities(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CAPACIDADES_INVALIDAS');
        }
        $items = [];
        foreach ($payload as $item) {
            $raw = is_array($item) ? ($item['codigo_capacidad'] ?? '') : $item;
            $code = strtoupper(trim((string)$raw));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $code)) {
                throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CAPACIDAD_INVALIDA');
            }
            $items[$code] = [
                'codigo_capacidad' => $code,
                'habilitada_por_defecto' => !is_array($item) || self::toBool($item['habilitada_por_defecto'] ?? true),
            ];
        }
        return array_values($items);
    }

    private static function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function assertCode(string $code): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,79}$/', $code)) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_CODIGO_INVALIDO');
        }
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private static function normalizeOrder(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('CATEGORIA_NEGOCIO_ORDEN_INVALIDO');
        }
        return max(0, min(1000000, (int)$value));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function statementParams(array $data): array
    {
        return [
            ':codigo' => $data['codigo'],
            ':nombre' => $data['nombre'],
            ':descripcion' => $data['descripcion'],
            ':perfil_operativo' => $data['perfil_operativo'],
            ':activo' => $data['activo'],
            ':visible_publico' => $data['visible_publico'],
            ':orden' => $data['orden'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCategory(array $row): array
    {
        return [
            'id_categoria_negocio' => (int)$row['id_categoria_negocio'],
            'codigo' => (string)$row['codigo'],
            'nombre' => (string)$row['nombre'],
            'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
            'perfil_operativo' => (string)$row['perfil_operativo'],
            'activo' => self::toBool($row['activo'] ?? false),
            'visible_publico' => self::toBool($row['visible_publico'] ?? false),
            'orden' => (int)$row['orden'],
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private function audit(PDO $pdo, int $actorId, string $actorEmail, string $action, int $idCategory, ?array $before, array $after): void
    {
        $audit = $pdo->prepare('
            INSERT INTO admin.audit_log
                (actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent)
            VALUES
                (:actor, :email, :action, :target_type, :target_id, :before::jsonb, :after::jsonb, :ip, :ua)
        ');
        $audit->execute([
            ':actor' => $actorId ?: null,
            ':email' => $actorEmail ?: null,
            ':action' => $action,
            ':target_type' => 'saas_categoria_negocio',
            ':target_id' => $idCategory,
            ':before' => json_encode($before, JSON_UNESCAPED_UNICODE),
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'si', 'on'], true);
    }
}

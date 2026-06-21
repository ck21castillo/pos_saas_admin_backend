<?php
namespace PosAdmin\Service;

use PDO;

final class BusinessConfigService
{
    public const TYPE_GENERAL = 'GENERAL';
    public const TYPE_DROGUERIA = 'DROGUERIA';
    public const TYPE_TIENDA_MINIMARKET = 'TIENDA_MINIMARKET';

    public static function validBusinessTypes(): array
    {
        return [
            self::TYPE_GENERAL,
            self::TYPE_DROGUERIA,
            self::TYPE_TIENDA_MINIMARKET,
        ];
    }

    public static function normalizeBusinessType(?string $value): string
    {
        $type = strtoupper(trim((string)$value));
        return in_array($type, self::validBusinessTypes(), true)
            ? $type
            : self::TYPE_GENERAL;
    }

    public static function getConfig(PDO $pdo, int $empresaId): array
    {
        $empresa = self::getEmpresa($pdo, $empresaId);
        $details = self::listCapabilities($pdo, $empresaId);
        $enabledMap = [];

        foreach ($details as $row) {
            $enabledMap[(string)$row['codigo_capacidad']] = (bool)$row['enabled'];
        }

        return [
            'id_empresa' => (int)$empresa['id_empresa'],
            'nombre' => (string)$empresa['nombre'],
            'tipo_negocio' => self::normalizeBusinessType((string)$empresa['tipo_negocio']),
            'capacidades' => $enabledMap,
            'capacidades_detalle' => $details,
            'tenant' => self::getTenantMapping($pdo, $empresaId),
        ];
    }

    public static function getEmpresa(PDO $pdo, int $empresaId): array
    {
        $st = $pdo->prepare('
            SELECT id_empresa, nombre, tipo_negocio
            FROM pos_saas.empresa
            WHERE id_empresa = :id
            LIMIT 1
        ');
        $st->execute([':id' => $empresaId]);
        $empresa = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($empresa) ? $empresa : [];
    }

    public static function listCapabilities(PDO $pdo, int $empresaId): array
    {
        $st = $pdo->prepare('
            SELECT
                c.codigo_capacidad,
                c.nombre,
                c.descripcion,
                c.estado,
                COALESCE(ec.enabled, false) AS enabled
            FROM pos_saas.capacidad c
            LEFT JOIN pos_saas.empresa_capacidad ec
              ON ec.codigo_capacidad = c.codigo_capacidad
             AND ec.id_empresa = :e
            WHERE c.estado = 1
            ORDER BY c.codigo_capacidad ASC
        ');
        $st->execute([':e' => $empresaId]);

        return array_map(static function (array $row): array {
            return [
                'codigo_capacidad' => (string)$row['codigo_capacidad'],
                'nombre' => (string)$row['nombre'],
                'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
                'estado' => (int)$row['estado'],
                'enabled' => self::toBool($row['enabled'] ?? false),
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function validCapabilityCodes(PDO $pdo): array
    {
        $st = $pdo->query('
            SELECT codigo_capacidad
            FROM pos_saas.capacidad
            WHERE estado = 1
            ORDER BY codigo_capacidad ASC
        ');

        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public static function getTenantMapping(PDO $pdo, int $empresaId): ?array
    {
        $st = $pdo->prepare('
            SELECT
                modo,
                db_host,
                db_port,
                db_name,
                db_schema,
                db_user,
                estado,
                notas,
                created_at,
                updated_at
            FROM admin.tenant_database
            WHERE id_empresa = :id
            LIMIT 1
        ');
        $st->execute([':id' => $empresaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'modo' => $row['modo'] !== null ? (string)$row['modo'] : null,
            'db_host' => $row['db_host'] !== null ? (string)$row['db_host'] : null,
            'db_port' => $row['db_port'] !== null ? (string)$row['db_port'] : null,
            'db_name' => $row['db_name'] !== null ? (string)$row['db_name'] : null,
            'db_schema' => $row['db_schema'] !== null ? (string)$row['db_schema'] : null,
            'db_user' => $row['db_user'] !== null ? (string)$row['db_user'] : null,
            'estado' => $row['estado'] !== null ? (string)$row['estado'] : null,
            'notas' => $row['notas'] !== null ? (string)$row['notas'] : null,
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
        ];
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true);
    }
}

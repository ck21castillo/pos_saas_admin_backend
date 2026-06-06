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

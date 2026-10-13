<?php
namespace PosAdmin\Service;

use PDO;

final class BusinessConfigService
{
    /** @var array<string, bool> */
    private static array $empresaColumnCache = [];

    public const TYPE_GENERAL = 'GENERAL';
    public const TYPE_DROGUERIA = 'DROGUERIA';
    public const TYPE_TIENDA_MINIMARKET = 'TIENDA_MINIMARKET';
    public const TYPE_TECNOLOGIA_SERVICIO_TECNICO = 'TECNOLOGIA_SERVICIO_TECNICO';
    public const TYPE_FERRETERIA = 'FERRETERIA';
    public const TYPE_MISCELANEA_PAPELERIA = 'MISCELANEA_PAPELERIA';
    public const TYPE_COLMENA = 'COLMENA';
    public const TYPE_ROPA_CALZADO = 'ROPA_CALZADO';
    public const TYPE_COSMETICA_BELLEZA = 'COSMETICA_BELLEZA';
    public const TYPE_REPUESTOS_ACCESORIOS = 'REPUESTOS_ACCESORIOS';
    public const TYPE_TIENDA_MASCOTAS = 'TIENDA_MASCOTAS';

    public const CAP_LOTES_VENCIMIENTOS = 'LOTES_VENCIMIENTOS';
    public const CAP_PRODUCTOS_PESO = 'PRODUCTOS_PESO';
    public const CAP_PRODUCTOS_PRESENTACION = 'PRODUCTOS_PRESENTACION';

    /**
     * Perfiles tecnicos estables. Las categorias comerciales se administran
     * aparte y solo pueden apuntar a uno de estos perfiles.
     *
     * @return list<string>
     */
    public static function validOperationalProfiles(): array
    {
        return [
            self::TYPE_GENERAL,
            self::TYPE_DROGUERIA,
            self::TYPE_TIENDA_MINIMARKET,
            self::TYPE_TECNOLOGIA_SERVICIO_TECNICO,
        ];
    }

    /**
     * Alias de compatibilidad para consumidores antiguos. Un tipo de negocio
     * escribible es siempre un perfil operativo, no una categoria comercial.
     *
     * @return list<string>
     */
    public static function validBusinessTypes(): array
    {
        return self::validOperationalProfiles();
    }

    public static function normalizeOperationalProfile(?string $value): string
    {
        $type = strtoupper(trim((string)$value));
        return in_array($type, self::validOperationalProfiles(), true)
            ? $type
            : self::TYPE_GENERAL;
    }

    /**
     * Conservado para llamadas existentes durante la migracion de contrato.
     */
    public static function normalizeBusinessType(?string $value): string
    {
        return self::normalizeOperationalProfile($value);
    }

    public static function getConfig(PDO $pdo, int $empresaId): array
    {
        $empresa = self::getEmpresa($pdo, $empresaId);
        $details = self::listCapabilities($pdo, $empresaId);
        $enabledMap = [];

        foreach ($details as $row) {
            $enabledMap[(string)$row['codigo_capacidad']] = (bool)$row['enabled'];
        }

        $categoriaNegocio = array_key_exists('categoria_negocio', $empresa) && $empresa['categoria_negocio'] !== null
            ? (string)$empresa['categoria_negocio']
            : null;
        $config = [
            'id_empresa' => (int)$empresa['id_empresa'],
            'nombre' => (string)$empresa['nombre'],
            'tipo_negocio' => self::normalizeOperationalProfile((string)$empresa['tipo_negocio']),
            'categoria_negocio' => $categoriaNegocio,
            'categorias_disponibles' => (new BusinessCategoryService())->listActiveForCompany($pdo, $categoriaNegocio),
            'capacidades' => $enabledMap,
            'capacidades_detalle' => $details,
            'tenant' => self::getTenantMapping($pdo, $empresaId),
        ];

        return $config;
    }

    public static function getEmpresa(PDO $pdo, int $empresaId): array
    {
        $categorySelect = self::empresaColumnExists($pdo, 'categoria_negocio')
            ? ', categoria_negocio'
            : '';
        $st = $pdo->prepare('
            SELECT id_empresa, nombre, tipo_negocio' . $categorySelect . '
            FROM pos_saas.empresa
            WHERE id_empresa = :id
            LIMIT 1
        ');
        $st->execute([':id' => $empresaId]);
        $empresa = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($empresa) ? $empresa : [];
    }

    private static function empresaColumnExists(PDO $pdo, string $column): bool
    {
        $key = spl_object_id($pdo) . ':pos_saas.empresa:' . $column;
        if (array_key_exists($key, self::$empresaColumnCache)) {
            return self::$empresaColumnCache[$key];
        }

        $st = $pdo->prepare('
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = :schema
                  AND table_name = :table
                  AND column_name = :column
            )
        ');
        $st->execute([
            ':schema' => 'pos_saas',
            ':table' => 'empresa',
            ':column' => $column,
        ]);

        return self::$empresaColumnCache[$key] = self::toBool($st->fetchColumn());
    }

    public static function listCapabilities(PDO $pdo, int $empresaId): array
    {
        $managedCodes = array_fill_keys((new SaasCapabilityService())->managedCodes(), true);
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

        return array_map(static function (array $row) use ($managedCodes): array {
            $code = (string)$row['codigo_capacidad'];
            $managed = isset($managedCodes[$code]);
            return [
                'codigo_capacidad' => $code,
                'nombre' => (string)$row['nombre'],
                'descripcion' => $row['descripcion'] !== null ? (string)$row['descripcion'] : null,
                'estado' => (int)$row['estado'],
                'enabled' => self::toBool($row['enabled'] ?? false),
                'gestion' => $managed ? 'SAAS' : 'EMPRESA',
                'editable_por_empresa' => !$managed,
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

<?php

namespace PosAdmin\Core;

use Dotenv\Dotenv;
use PDO;
use PDOException;

class Database
{
    /** @var PDO|null */
    private static $instance = null;

    /**
     * Devuelve una conexion PDO reutilizable.
     *
     * @return PDO
     * @throws \RuntimeException
     */
    public static function getConnection(): PDO
    {
        if (self::$instance !== null && self::connectionIsAlive(self::$instance)) {
            return self::$instance;
        }

        self::$instance = null;
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->safeLoad();

        $dsn = (string)($_ENV['DB_DSN'] ?? '');
        if ($dsn === '') {
            $host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
            $port = (string)($_ENV['DB_PORT'] ?? '5432');
            $db = (string)($_ENV['DB_DATABASE'] ?? '');
            if ($db === '') {
                throw new \RuntimeException('DB_DATABASE is required when DB_DSN is not set');
            }

            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
                $host,
                $port,
                $db
            );
        }

        $user = (string)($_ENV['DB_USER'] ?? '');
        $password = (string)($_ENV['DB_PASSWORD'] ?? ($_ENV['DB_PASS'] ?? ''));
        if ($user === '') {
            throw new \RuntimeException('DB_USER is required');
        }

        $persistent = (($_ENV['DB_PERSISTENT'] ?? '0') === '1');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => $persistent,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            $debug = (($_ENV['APP_DEBUG'] ?? '0') === '1');
            $suffix = $debug ? ': ' . $e->getMessage() : '';
            throw new \RuntimeException('Error de conexion a la BD' . $suffix);
        }

        return self::$instance;
    }

    /**
     * Construye la configuracion para una conexion tenant sin abrirla.
     * En produccion las credenciales tenant deben declararse por separado para
     * impedir que una configuracion de control se reutilice silenciosamente.
     *
     * @param array<string, mixed> $tenant
     * @param array<string, mixed>|null $environment
     * @return array{dsn:string,user:string,password:string}
     */
    public static function tenantConnectionSettings(string $database, array $tenant = [], ?array $environment = null): array
    {
        $environment ??= self::environment();
        $database = trim($database);
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]*$/', $database)) {
            throw new \RuntimeException('TENANT_DATABASE_INVALID');
        }

        $host = self::envValue($environment, 'DB_TENANT_HOST');
        if ($host === '') {
            $host = trim((string)($tenant['db_host'] ?? '')) ?: self::envValue($environment, 'DB_HOST', 'localhost');
        }
        $port = self::envValue($environment, 'DB_TENANT_PORT');
        if ($port === '') {
            $port = trim((string)($tenant['db_port'] ?? '')) ?: self::envValue($environment, 'DB_PORT', '5432');
        }

        $tenantUser = self::envValue($environment, 'DB_TENANT_USER');
        $tenantPassword = self::envValue($environment, 'DB_TENANT_PASSWORD');
        if (self::isProduction($environment) && ($tenantUser === '' || $tenantPassword === '')) {
            throw new \RuntimeException('DB_TENANT_CREDENTIALS_REQUIRED');
        }

        $user = $tenantUser;
        $password = $tenantPassword;
        if ($user === '') {
            $user = trim((string)($tenant['db_user'] ?? '')) ?: self::envValue($environment, 'DB_USER');
        }
        if ($password === '') {
            $password = self::envValue($environment, 'DB_PASSWORD') ?: self::envValue($environment, 'DB_PASS');
        }
        if ($user === '' || $password === '') {
            throw new \RuntimeException('DB_TENANT_CREDENTIALS_REQUIRED');
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $host)) {
            throw new \RuntimeException('TENANT_HOST_INVALID');
        }
        if (!ctype_digit($port) || (int)$port <= 0) {
            throw new \RuntimeException('TENANT_PORT_INVALID');
        }

        return [
            'dsn' => sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
                $host,
                $port,
                $database
            ),
            'user' => $user,
            'password' => $password,
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<int, mixed> $options
     */
    public static function connectTenant(string $database, array $tenant = [], array $options = []): PDO
    {
        $settings = self::tenantConnectionSettings($database, $tenant);
        try {
            return new PDO($settings['dsn'], $settings['user'], $settings['password'], array_replace([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ], $options));
        } catch (PDOException $e) {
            throw new \RuntimeException('TENANT_CONNECTION_FAILED', 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private static function environment(): array
    {
        return array_merge($_SERVER, $_ENV);
    }

    /** @param array<string, mixed> $environment */
    private static function envValue(array $environment, string $key, string $default = ''): string
    {
        $value = $environment[$key] ?? getenv($key) ?: $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @param array<string, mixed> $environment */
    private static function isProduction(array $environment): bool
    {
        return in_array(strtolower(self::envValue($environment, 'APP_ENV')), ['prod', 'production'], true);
    }

    private static function connectionIsAlive(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }
}

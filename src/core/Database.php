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
        if (self::$instance === null) {
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

            $persistent = (($_ENV['DB_PERSISTENT'] ?? '1') === '1');
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
        }

        return self::$instance;
    }
}

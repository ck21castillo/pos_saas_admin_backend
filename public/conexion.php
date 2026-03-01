<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PosAdmin\Core\Database;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$isCli = PHP_SAPI === 'cli';
$appEnv = (string)($_ENV['APP_ENV'] ?? 'production');

if (!$isCli && $appEnv !== 'local') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $db = Database::getConnection();
    $ok = (bool)$db->query('SELECT 1')->fetchColumn();
    echo $ok ? 'Conexion PDO exitosa a bersano_pos' : 'Conexion no valida';
} catch (\Throwable $e) {
    $debug = (($_ENV['APP_DEBUG'] ?? '0') === '1');
    $msg = $debug ? ('Error: ' . $e->getMessage()) : 'Error: fallo interno';
    echo $msg;
}

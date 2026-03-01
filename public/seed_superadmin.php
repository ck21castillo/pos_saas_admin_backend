<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PosAdmin\Core\Database;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$isCli = PHP_SAPI === 'cli';
$appEnv = (string)($_ENV['APP_ENV'] ?? 'production');
$allowWebSeed = (($_ENV['ALLOW_SEED_SUPERADMIN'] ?? '0') === '1');

if (!$isCli) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($appEnv !== 'local' || !$allowWebSeed || $method !== 'POST') {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

[$email, $pass] = (function () use ($isCli): array {
    if ($isCli) {
        global $argv;
        $email = 'admin@bersano.com';
        $pass = 'Admin123*';
        foreach (($argv ?? []) as $arg) {
            if (strpos($arg, '--email=') === 0) {
                $email = substr($arg, 8);
            }
            if (strpos($arg, '--pass=') === 0) {
                $pass = substr($arg, 7);
            }
        }
        return [$email, $pass];
    }

    $email = (string)($_POST['email'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    return [trim($email), $pass];
})();

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
    http_response_code(422);
    echo "Error: email/pass invalidos\n";
    exit;
}

try {
    $pdo = Database::getConnection();

    $st = $pdo->prepare('SELECT id_superadmin FROM admin.superadmin_user WHERE email = :email');
    $st->execute([':email' => $email]);
    $id = $st->fetchColumn();

    if ($id) {
        echo "Ya existe superadmin: {$email} (id={$id})\n";
        exit;
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $ins = $pdo->prepare('
        INSERT INTO admin.superadmin_user (email, password_hash)
        VALUES (:email, :hash)
        RETURNING id_superadmin
    ');
    $ins->execute([':email' => $email, ':hash' => $hash]);
    $newId = $ins->fetchColumn();

    echo "Superadmin creado: {$email} (id={$newId})\n";
} catch (\Throwable $e) {
    $debug = (($_ENV['APP_DEBUG'] ?? '0') === '1');
    $msg = $debug ? ('Error: ' . $e->getMessage()) : 'Error: fallo interno';
    echo $msg . "\n";
}

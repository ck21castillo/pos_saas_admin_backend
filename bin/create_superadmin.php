<?php

declare(strict_types=1);

use PosAdmin\Core\Database;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$options = getopt('', ['email:', 'password-stdin']);
$email = strtolower(trim((string)($options['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/create_superadmin.php --email=admin@dominio.com --password-stdin\n");
    exit(1);
}

if (!array_key_exists('password-stdin', $options)) {
    fwrite(STDERR, "Por seguridad la clave debe recibirse por entrada estandar con --password-stdin.\n");
    exit(1);
}

$password = rtrim((string)stream_get_contents(STDIN), "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "La clave debe tener al menos 12 caracteres.\n");
    exit(1);
}

try {
    $pdo = Database::getConnection();
    $existing = $pdo->prepare('SELECT id_superadmin FROM admin.superadmin_user WHERE lower(email) = lower(:email) LIMIT 1');
    $existing->execute([':email' => $email]);
    if ($existing->fetchColumn()) {
        fwrite(STDERR, "Ya existe un superadmin con ese correo. No se hicieron cambios.\n");
        exit(2);
    }

    $insert = $pdo->prepare('
        INSERT INTO admin.superadmin_user (email, password_hash)
        VALUES (:email, :password_hash)
        RETURNING id_superadmin
    ');
    $insert->execute([
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);

    fwrite(STDOUT, 'Superadmin creado. id=' . (int)$insert->fetchColumn() . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('[create_superadmin] ' . $e->getMessage());
    fwrite(STDERR, "No se pudo crear el superadmin. Revise la configuracion y los logs del servidor.\n");
    exit(1);
}

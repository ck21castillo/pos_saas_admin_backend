<?php

require __DIR__ . '/../vendor/autoload.php';

use PosAdmin\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

$email = $_GET['email'] ?? 'admin@bersano.com';
$pass  = $_GET['pass'] ?? 'Admin123*';

try {
    $pdo = Database::getConnection();

    // existe?
    $st = $pdo->prepare("SELECT id_superadmin FROM admin.superadmin_user WHERE email = :email");
    $st->execute([':email' => $email]);
    $id = $st->fetchColumn();

    if ($id) {
        echo "✅ Ya existe superadmin: $email (id=$id)\n";
        exit;
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $ins = $pdo->prepare("
        INSERT INTO admin.superadmin_user (email, password_hash)
        VALUES (:email, :hash)
        RETURNING id_superadmin
    ");
    $ins->execute([':email' => $email, ':hash' => $hash]);
    $newId = $ins->fetchColumn();

    echo "✅ Superadmin creado: $email (id=$newId)\n";
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

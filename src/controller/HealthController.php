<?php
namespace PosAdmin\Controller;

use PosAdmin\Core\Database;
use PosAdmin\Core\Response;

final class HealthController {
  public function ping(): void {
    $pdo = Database::getConnection();
    $ok = (bool)$pdo->query("SELECT 1")->fetchColumn();
    Response::json(['ok' => $ok, 'service' => 'pos_saas_admin']);
  }
}

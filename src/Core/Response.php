<?php
namespace PosAdmin\Core;

final class Response {
  public static function json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }

  public static function error(string $code, int $status = 400, ?string $message = null): void {
    $payload = ['error' => $code];
    if ($message !== null && $message !== '') {
      $payload['message'] = $message;
    }
    self::json($payload, $status);
  }
}

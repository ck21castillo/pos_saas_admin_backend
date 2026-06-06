<?php
namespace PosAdmin\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    public static function sign(array $payload, string $secret): string
    {
        return JWT::encode($payload, $secret, 'HS256');
    }

    public static function verify(string $token, string $secret): array
    {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        return (array)$decoded;
    }
}

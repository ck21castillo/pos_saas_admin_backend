<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use RuntimeException;

final class LandingAnalyticsInput
{
    private const MAX_VISITOR_ID = 120;
    private const MAX_URL = 2048;
    private const MAX_USER_AGENT = 512;
    private const MAX_UTM = 256;

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public static function normalize(array $body, ?string $userAgent = null): array
    {
        $visitorId = self::text($body['visitor_id'] ?? null, self::MAX_VISITOR_ID, 'VISITOR_ID_INVALIDO');
        if (strlen($visitorId) < 8) {
            throw new RuntimeException('VISITOR_ID_INVALIDO');
        }

        $landingPath = self::text($body['landing_path'] ?? '/', 64, 'LANDING_PATH_INVALIDO');
        if ($landingPath === '') {
            $landingPath = '/';
        }
        if ($landingPath[0] !== '/') {
            $landingPath = '/' . $landingPath;
        }
        if (!in_array($landingPath, ['/', '/crear-negocio'], true)) {
            throw new RuntimeException('LANDING_PATH_INVALIDO');
        }

        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $field) {
            $utm[$field] = self::text($body[$field] ?? null, self::MAX_UTM, 'LANDING_ANALYTICS_FIELD_TOO_LONG');
        }

        return [
            'visitor_id' => $visitorId,
            'landing_path' => $landingPath,
            'page_location' => self::nullableText($body['page_location'] ?? null, self::MAX_URL),
            'referrer' => self::nullableText($body['referrer'] ?? null, self::MAX_URL),
            'user_agent' => self::nullableText($userAgent, self::MAX_USER_AGENT),
            'utm' => $utm,
        ];
    }

    public static function clientIp(): ?string
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $trustedProxies = array_filter(array_map('trim', explode(',', (string)($_ENV['TRUSTED_PROXY_IPS'] ?? ''))));
        if (in_array($remote, $trustedProxies, true)) {
            foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    private static function nullableText(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::text($value, $limit, 'LANDING_ANALYTICS_FIELD_TOO_LONG');
    }

    private static function text(mixed $value, int $limit, string $error): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new RuntimeException($error);
        }
        $text = trim((string)$value);
        if (strlen($text) > $limit) {
            throw new RuntimeException($error);
        }
        return $text;
    }
}

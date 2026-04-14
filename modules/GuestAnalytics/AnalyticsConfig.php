<?php

function guestAnalyticsBoolEnv(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function guestAnalyticsConfig(string $baseDir, bool $isSecureRequest): array
{
    $dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data';

    return [
        'data_dir' => $dataDir,
        'sqlite_file' => $dataDir . DIRECTORY_SEPARATOR . 'guest_analytics.sqlite',
        'fallback_file' => $dataDir . DIRECTORY_SEPARATOR . 'guest_analytics.json',
        'outbox_file' => $dataDir . DIRECTORY_SEPARATOR . 'onecbc_guest_outbox.json',
        'visitor_cookie' => 'cbc360tour_guest_id',
        'cookie_options' => [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $isSecureRequest,
            'httponly' => false,
            'samesite' => 'Lax',
        ],
        'api' => [
            'enabled' => guestAnalyticsBoolEnv('ONECBC_ANALYTICS_ENABLED', false),
            'url' => trim((string) getenv('ONECBC_ANALYTICS_URL')),
            'token' => trim((string) getenv('ONECBC_ANALYTICS_TOKEN')),
            'allowed_host' => trim((string) getenv('ONECBC_ANALYTICS_ALLOWED_HOST')),
            'audience' => trim((string) getenv('ONECBC_ANALYTICS_AUDIENCE')),
            'source' => trim((string) getenv('ONECBC_ANALYTICS_SOURCE')) ?: 'cbc-360-tour',
            'connect_timeout_ms' => max(100, (int) (getenv('ONECBC_ANALYTICS_CONNECT_TIMEOUT_MS') ?: 300)),
            'timeout_ms' => max(300, (int) (getenv('ONECBC_ANALYTICS_TIMEOUT_MS') ?: 1500)),
            'verify_tls' => !guestAnalyticsBoolEnv('ONECBC_ANALYTICS_SKIP_TLS_VERIFY', false),
            'hmac_secret' => trim((string) getenv('ONECBC_ANALYTICS_HMAC_SECRET')),
        ],
    ];
}

<?php

function guestAnalyticsHasValidApiConfig(array $config): bool
{
    if (empty($config['enabled']) || empty($config['url']) || empty($config['token'])) {
        return false;
    }

    $url = filter_var($config['url'], FILTER_VALIDATE_URL);
    if ($url === false) {
        return false;
    }

    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') {
        return false;
    }

    if (!empty($config['allowed_host']) && strcasecmp((string) ($parts['host'] ?? ''), $config['allowed_host']) !== 0) {
        return false;
    }

    return true;
}

function guestAnalyticsBuildApiEnvelope(array $payload, array $config): array
{
    $body = [
        'source' => $config['source'],
        'event' => 'guest.visit.recorded',
        'idempotency_key' => $payload['session_id'],
        'sent_at_utc' => gmdate('c'),
        'guest' => $payload,
    ];

    if (!empty($config['audience'])) {
        $body['audience'] = $config['audience'];
    }

    return $body;
}

function guestAnalyticsStoreOutbox(string $outboxFile, array $envelope): void
{
    $handle = fopen($outboxFile, 'c+');
    if (!$handle) {
        return;
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $items = json_decode($raw ?: '', true);
    if (!is_array($items)) {
        $items = [];
    }

    $items[$envelope['idempotency_key']] = $envelope;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function guestAnalyticsRemoveOutboxItem(string $outboxFile, string $idempotencyKey): void
{
    if (!is_file($outboxFile)) {
        return;
    }

    $handle = fopen($outboxFile, 'c+');
    if (!$handle) {
        return;
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $items = json_decode($raw ?: '', true);
    if (!is_array($items)) {
        $items = [];
    }

    unset($items[$idempotencyKey]);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function guestAnalyticsSendToOneCBC(array $payload, array $apiConfig, string $outboxFile): array
{
    if (!guestAnalyticsHasValidApiConfig($apiConfig) || !function_exists('curl_init')) {
        return ['status' => 'skipped'];
    }

    $envelope = guestAnalyticsBuildApiEnvelope($payload, $apiConfig);
    $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['status' => 'failed', 'reason' => 'json_encode_failed'];
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiConfig['token'],
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json),
        'User-Agent: CBC360TourAnalytics/1.0',
        'X-Guest-Event: guest.visit.recorded',
        'X-Idempotency-Key: ' . $envelope['idempotency_key'],
        'X-Analytics-Source: ' . $apiConfig['source'],
    ];

    if (!empty($apiConfig['hmac_secret'])) {
        $headers[] = 'X-Signature: sha256=' . hash_hmac('sha256', $json, $apiConfig['hmac_secret']);
    }

    $ch = curl_init($apiConfig['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT_MS => $apiConfig['connect_timeout_ms'],
        CURLOPT_TIMEOUT_MS => $apiConfig['timeout_ms'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => $apiConfig['verify_tls'],
        CURLOPT_SSL_VERIFYHOST => $apiConfig['verify_tls'] ? 2 : 0,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response !== false && $statusCode >= 200 && $statusCode < 300) {
        guestAnalyticsRemoveOutboxItem($outboxFile, $envelope['idempotency_key']);
        return ['status' => 'sent', 'http_code' => $statusCode];
    }

    guestAnalyticsStoreOutbox($outboxFile, $envelope);

    return [
        'status' => 'queued',
        'http_code' => $statusCode,
        'reason' => $curlError ?: 'remote_rejected',
    ];
}

<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AnalyticsConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LocalAnalytics.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'OneCBCAnalyticsApi.php';

function guestAnalyticsBootstrap(string $baseDir): array
{
    $isSecureRequest = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $config = guestAnalyticsConfig($baseDir, $isSecureRequest);
    [$payload, $stats] = guestAnalyticsRecordLocal($config);
    $delivery = guestAnalyticsSendToOneCBC($payload, $config['api'], $config['outbox_file']);

    return [
        'payload' => $payload,
        'stats' => $stats,
        'delivery' => $delivery,
        'config' => $config,
    ];
}

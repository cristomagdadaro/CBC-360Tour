<?php

function guestAnalyticsEnsureDataDir(string $dataDir): void
{
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0777, true);
    }
}

function guestAnalyticsGenerateId(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return sha1(uniqid((string) mt_rand(), true));
    }
}

function guestAnalyticsClientIp(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $value = trim(explode(',', (string) $_SERVER[$key])[0]);
        if ($value !== '') {
            return $value;
        }
    }

    return 'unknown';
}

function guestAnalyticsUserAgentFamily(string $userAgent): string
{
    $map = [
        'Edge' => ['Edg/', 'Edge/'],
        'Chrome' => ['Chrome/', 'CriOS/'],
        'Firefox' => ['Firefox/'],
        'Safari' => ['Safari/'],
        'Opera' => ['OPR/', 'Opera/'],
    ];

    foreach ($map as $family => $needles) {
        foreach ($needles as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return $family;
            }
        }
    }

    return 'Other';
}

function guestAnalyticsOsFamily(string $userAgent): string
{
    $map = [
        'Windows' => ['Windows'],
        'Android' => ['Android'],
        'iOS' => ['iPhone', 'iPad', 'iPod'],
        'macOS' => ['Mac OS X', 'Macintosh'],
        'Linux' => ['Linux'],
    ];

    foreach ($map as $family => $needles) {
        foreach ($needles as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return $family;
            }
        }
    }

    return 'Other';
}

function guestAnalyticsBuildPayload(string $visitorId, string $source): array
{
    $ipAddress = guestAnalyticsClientIp();
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $acceptLanguage = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $query = (string) parse_url($requestUri, PHP_URL_QUERY);
    $isMobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent);

    return [
        'visitor_id' => $visitorId,
        'session_id' => guestAnalyticsGenerateId(),
        'visited_at_utc' => gmdate('c'),
        'ip_hash' => hash('sha256', $ipAddress),
        'user_agent' => $userAgent,
        'browser_family' => guestAnalyticsUserAgentFamily($userAgent),
        'os_family' => guestAnalyticsOsFamily($userAgent),
        'accept_language' => $acceptLanguage,
        'referrer' => $referrer,
        'landing_path' => $path,
        'query_string' => $query,
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        'is_mobile' => $isMobile ? 1 : 0,
        'source' => $source,
    ];
}

function guestAnalyticsIsTrackableRequest(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $normalizedPath = strtolower(rtrim($path, '/'));
    if ($normalizedPath === '') {
        $normalizedPath = '/';
    }

    if (!in_array($normalizedPath, ['/', '/index.php'], true)) {
        return false;
    }

    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return false;
    }

    $blockedUserAgentPatterns = [
        'bot',
        'spider',
        'crawler',
        'scan',
        'curl',
        'wget',
        'python-requests',
        'go-http-client',
        'axios',
        'node-fetch',
        'bytespider',
        'headless',
        'facebookexternalhit',
        'monitor',
    ];

    $normalizedUserAgent = strtolower($userAgent);
    foreach ($blockedUserAgentPatterns as $pattern) {
        if (strpos($normalizedUserAgent, $pattern) !== false) {
            return false;
        }
    }

    $acceptHeader = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));
    if ($acceptHeader !== '' && strpos($acceptHeader, 'text/html') === false && strpos($acceptHeader, '*/*') === false) {
        return false;
    }

    return true;
}

function guestAnalyticsWriteSqlite(string $sqliteFile, array $payload): ?array
{
    if (!class_exists('SQLite3')) {
        return null;
    }

    $db = new SQLite3($sqliteFile);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS visitors (
        visitor_id TEXT PRIMARY KEY,
        first_seen_utc TEXT NOT NULL,
        last_seen_utc TEXT NOT NULL,
        visit_count INTEGER NOT NULL DEFAULT 0,
        ip_hash TEXT,
        user_agent TEXT,
        browser_family TEXT,
        os_family TEXT,
        accept_language TEXT,
        referrer TEXT,
        landing_path TEXT,
        query_string TEXT,
        host TEXT,
        is_mobile INTEGER NOT NULL DEFAULT 0,
        source TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id TEXT NOT NULL,
        session_id TEXT NOT NULL UNIQUE,
        visited_at_utc TEXT NOT NULL,
        ip_hash TEXT,
        user_agent TEXT,
        browser_family TEXT,
        os_family TEXT,
        accept_language TEXT,
        referrer TEXT,
        landing_path TEXT,
        query_string TEXT,
        host TEXT,
        is_mobile INTEGER NOT NULL DEFAULT 0,
        source TEXT
    )');

    $db->exec('BEGIN IMMEDIATE');

    $insertVisit = $db->prepare('INSERT OR IGNORE INTO visits (
        visitor_id, session_id, visited_at_utc, ip_hash, user_agent, browser_family, os_family,
        accept_language, referrer, landing_path, query_string, host, is_mobile, source
    ) VALUES (
        :visitor_id, :session_id, :visited_at_utc, :ip_hash, :user_agent, :browser_family, :os_family,
        :accept_language, :referrer, :landing_path, :query_string, :host, :is_mobile, :source
    )');

    foreach ($payload as $key => $value) {
        $insertVisit->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $insertVisit->execute();

    $upsertVisitor = $db->prepare('INSERT INTO visitors (
        visitor_id, first_seen_utc, last_seen_utc, visit_count, ip_hash, user_agent, browser_family,
        os_family, accept_language, referrer, landing_path, query_string, host, is_mobile, source
    ) VALUES (
        :visitor_id, :visited_at_utc, :visited_at_utc, 1, :ip_hash, :user_agent, :browser_family,
        :os_family, :accept_language, :referrer, :landing_path, :query_string, :host, :is_mobile, :source
    )
    ON CONFLICT(visitor_id) DO UPDATE SET
        last_seen_utc = excluded.last_seen_utc,
        visit_count = visitors.visit_count + 1,
        ip_hash = excluded.ip_hash,
        user_agent = excluded.user_agent,
        browser_family = excluded.browser_family,
        os_family = excluded.os_family,
        accept_language = excluded.accept_language,
        referrer = excluded.referrer,
        landing_path = excluded.landing_path,
        query_string = excluded.query_string,
        host = excluded.host,
        is_mobile = excluded.is_mobile,
        source = excluded.source');

    foreach ($payload as $key => $value) {
        $upsertVisitor->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $upsertVisitor->execute();

    $stats = [
        'visit_count' => (int) $db->querySingle('SELECT COUNT(*) FROM visits'),
        'unique_visitors' => (int) $db->querySingle('SELECT COUNT(*) FROM visitors'),
        'storage_engine' => 'sqlite',
    ];

    $db->exec('COMMIT');
    $db->close();

    return $stats;
}

function guestAnalyticsWriteJson(string $fallbackFile, array $payload): array
{
    $handle = fopen($fallbackFile, 'c+');
    if (!$handle) {
        return ['visit_count' => 0, 'unique_visitors' => 0, 'storage_engine' => 'unavailable'];
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = ['visits' => [], 'visitors' => []];
    }

    $visitorId = $payload['visitor_id'];
    if (!isset($data['visitors'][$visitorId])) {
        $data['visitors'][$visitorId] = [
            'visitor_id' => $visitorId,
            'first_seen_utc' => $payload['visited_at_utc'],
            'last_seen_utc' => $payload['visited_at_utc'],
            'visit_count' => 0,
        ];
    }

    $data['visitors'][$visitorId] = array_merge($data['visitors'][$visitorId], [
        'last_seen_utc' => $payload['visited_at_utc'],
        'visit_count' => (int) $data['visitors'][$visitorId]['visit_count'] + 1,
        'ip_hash' => $payload['ip_hash'],
        'user_agent' => $payload['user_agent'],
        'browser_family' => $payload['browser_family'],
        'os_family' => $payload['os_family'],
        'accept_language' => $payload['accept_language'],
        'referrer' => $payload['referrer'],
        'landing_path' => $payload['landing_path'],
        'query_string' => $payload['query_string'],
        'host' => $payload['host'],
        'is_mobile' => $payload['is_mobile'],
        'source' => $payload['source'],
    ]);

    $data['visits'][] = $payload;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return [
        'visit_count' => count($data['visits']),
        'unique_visitors' => count($data['visitors']),
        'storage_engine' => 'json-fallback',
    ];
}

function guestAnalyticsRecordLocal(array $config): array
{
    guestAnalyticsEnsureDataDir($config['data_dir']);

    if (!guestAnalyticsIsTrackableRequest()) {
        return [null, [
            'visit_count' => guestAnalyticsReadVisitCount($config),
            'unique_visitors' => guestAnalyticsReadUniqueVisitorCount($config),
            'storage_engine' => guestAnalyticsDetectStorageEngine($config),
            'skipped' => true,
        ]];
    }

    $visitorCookie = $config['visitor_cookie'];
    $visitorId = !empty($_COOKIE[$visitorCookie]) ? (string) $_COOKIE[$visitorCookie] : guestAnalyticsGenerateId();
    if (empty($_COOKIE[$visitorCookie])) {
        @setcookie($visitorCookie, $visitorId, $config['cookie_options']);
        $_COOKIE[$visitorCookie] = $visitorId;
    }

    $payload = guestAnalyticsBuildPayload($visitorId, $config['api']['source']);
    $stats = guestAnalyticsWriteSqlite($config['sqlite_file'], $payload);
    if ($stats === null) {
        $stats = guestAnalyticsWriteJson($config['fallback_file'], $payload);
    }

    return [$payload, $stats];
}

function guestAnalyticsDetectStorageEngine(array $config): string
{
    if (class_exists('SQLite3') && is_file($config['sqlite_file'])) {
        return 'sqlite';
    }

    if (is_file($config['fallback_file'])) {
        return 'json-fallback';
    }

    return class_exists('SQLite3') ? 'sqlite' : 'json-fallback';
}

function guestAnalyticsReadVisitCount(array $config): int
{
    if (class_exists('SQLite3') && is_file($config['sqlite_file'])) {
        $db = new SQLite3($config['sqlite_file']);
        $count = (int) $db->querySingle('SELECT COUNT(*) FROM visits');
        $db->close();
        return $count;
    }

    if (is_file($config['fallback_file'])) {
        $raw = file_get_contents($config['fallback_file']);
        $data = json_decode($raw ?: '', true);
        if (is_array($data) && isset($data['visits']) && is_array($data['visits'])) {
            return count($data['visits']);
        }
    }

    return 0;
}

function guestAnalyticsReadUniqueVisitorCount(array $config): int
{
    if (class_exists('SQLite3') && is_file($config['sqlite_file'])) {
        $db = new SQLite3($config['sqlite_file']);
        $count = (int) $db->querySingle('SELECT COUNT(*) FROM visitors');
        $db->close();
        return $count;
    }

    if (is_file($config['fallback_file'])) {
        $raw = file_get_contents($config['fallback_file']);
        $data = json_decode($raw ?: '', true);
        if (is_array($data) && isset($data['visitors']) && is_array($data['visitors'])) {
            return count($data['visitors']);
        }
    }

    return 0;
}

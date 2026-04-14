<?php
$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$sqliteFile = $dataDir . DIRECTORY_SEPARATOR . 'guest_analytics.sqlite';
$fallbackFile = $dataDir . DIRECTORY_SEPARATOR . 'guest_analytics.json';
$visitorCookie = 'cbc360tour_guest_id';
$isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$cookieOptions = [
    'expires' => time() + 31536000,
    'path' => '/',
    'secure' => $isSecure,
    'httponly' => false,
    'samesite' => 'Lax',
];

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

function generateId(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return sha1(uniqid((string) mt_rand(), true));
    }
}

function getClientIp(): string
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

function buildGuestPayload(string $visitorId): array
{
    $ipAddress = getClientIp();
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $acceptLanguage = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $query = (string) parse_url($requestUri, PHP_URL_QUERY);
    $isMobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent);
    $nowUtc = gmdate('c');

    return [
        'visitor_id' => $visitorId,
        'session_id' => generateId(),
        'visited_at_utc' => $nowUtc,
        'ip_hash' => hash('sha256', $ipAddress),
        'user_agent' => $userAgent,
        'accept_language' => $acceptLanguage,
        'referrer' => $referrer,
        'landing_path' => $path,
        'query_string' => $query,
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        'is_mobile' => $isMobile ? 1 : 0,
    ];
}

function writeGuestToSqlite(string $sqliteFile, array $payload): ?array
{
    if (!class_exists('SQLite3')) {
        return null;
    }

    $db = new SQLite3($sqliteFile);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS visitors (
            visitor_id TEXT PRIMARY KEY,
            first_seen_utc TEXT NOT NULL,
            last_seen_utc TEXT NOT NULL,
            visit_count INTEGER NOT NULL DEFAULT 0,
            ip_hash TEXT,
            user_agent TEXT,
            accept_language TEXT,
            referrer TEXT,
            landing_path TEXT,
            query_string TEXT,
            host TEXT,
            is_mobile INTEGER NOT NULL DEFAULT 0
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_id TEXT NOT NULL,
            session_id TEXT NOT NULL,
            visited_at_utc TEXT NOT NULL,
            ip_hash TEXT,
            user_agent TEXT,
            accept_language TEXT,
            referrer TEXT,
            landing_path TEXT,
            query_string TEXT,
            host TEXT,
            is_mobile INTEGER NOT NULL DEFAULT 0
        )'
    );

    $db->exec('BEGIN IMMEDIATE');

    $insertVisit = $db->prepare(
        'INSERT INTO visits (
            visitor_id, session_id, visited_at_utc, ip_hash, user_agent, accept_language,
            referrer, landing_path, query_string, host, is_mobile
        ) VALUES (
            :visitor_id, :session_id, :visited_at_utc, :ip_hash, :user_agent, :accept_language,
            :referrer, :landing_path, :query_string, :host, :is_mobile
        )'
    );

    foreach ($payload as $key => $value) {
        $insertVisit->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $insertVisit->execute();

    $upsertVisitor = $db->prepare(
        'INSERT INTO visitors (
            visitor_id, first_seen_utc, last_seen_utc, visit_count, ip_hash, user_agent,
            accept_language, referrer, landing_path, query_string, host, is_mobile
        ) VALUES (
            :visitor_id, :visited_at_utc, :visited_at_utc, 1, :ip_hash, :user_agent,
            :accept_language, :referrer, :landing_path, :query_string, :host, :is_mobile
        )
        ON CONFLICT(visitor_id) DO UPDATE SET
            last_seen_utc = excluded.last_seen_utc,
            visit_count = visitors.visit_count + 1,
            ip_hash = excluded.ip_hash,
            user_agent = excluded.user_agent,
            accept_language = excluded.accept_language,
            referrer = excluded.referrer,
            landing_path = excluded.landing_path,
            query_string = excluded.query_string,
            host = excluded.host,
            is_mobile = excluded.is_mobile'
    );

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

function writeGuestToJson(string $fallbackFile, array $payload): array
{
    $handle = fopen($fallbackFile, 'c+');
    if (!$handle) {
        return [
            'visit_count' => 0,
            'unique_visitors' => 0,
            'storage_engine' => 'unavailable',
        ];
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
            'ip_hash' => $payload['ip_hash'],
            'user_agent' => $payload['user_agent'],
            'accept_language' => $payload['accept_language'],
            'referrer' => $payload['referrer'],
            'landing_path' => $payload['landing_path'],
            'query_string' => $payload['query_string'],
            'host' => $payload['host'],
            'is_mobile' => $payload['is_mobile'],
        ];
    }

    $data['visitors'][$visitorId]['last_seen_utc'] = $payload['visited_at_utc'];
    $data['visitors'][$visitorId]['visit_count']++;
    $data['visitors'][$visitorId]['ip_hash'] = $payload['ip_hash'];
    $data['visitors'][$visitorId]['user_agent'] = $payload['user_agent'];
    $data['visitors'][$visitorId]['accept_language'] = $payload['accept_language'];
    $data['visitors'][$visitorId]['referrer'] = $payload['referrer'];
    $data['visitors'][$visitorId]['landing_path'] = $payload['landing_path'];
    $data['visitors'][$visitorId]['query_string'] = $payload['query_string'];
    $data['visitors'][$visitorId]['host'] = $payload['host'];
    $data['visitors'][$visitorId]['is_mobile'] = $payload['is_mobile'];
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

$visitorId = !empty($_COOKIE[$visitorCookie]) ? (string) $_COOKIE[$visitorCookie] : generateId();
if (empty($_COOKIE[$visitorCookie])) {
    @setcookie($visitorCookie, $visitorId, $cookieOptions);
    $_COOKIE[$visitorCookie] = $visitorId;
}

$guestPayload = buildGuestPayload($visitorId);
$stats = writeGuestToSqlite($sqliteFile, $guestPayload);
if ($stats === null) {
    $stats = writeGuestToJson($fallbackFile, $guestPayload);
}

$visitCount = (int) ($stats['visit_count'] ?? 0);
$uniqueVisitors = (int) ($stats['unique_visitors'] ?? 0);
$storageEngine = (string) ($stats['storage_engine'] ?? 'unavailable');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>DA-CBC Virtual Tour</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="viewport" id="metaViewport"
          content="user-scalable=no, initial-scale=1, width=device-width, viewport-fit=cover"
          data-tdv-general-scale="1"/>
    <meta name="description" content="Virtual Tour: DA-Crop Biotechnology Center"/>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <script src="lib/tdvplayer.js?v=1731920986256"></script>
    <link rel="shortcut icon" href="favicon.ico?v=1731920986256">
    <link rel="icon" sizes="48x48 32x32 16x16" href="favicon.ico?v=1731920986256">
    <link rel="apple-touch-icon" type="image/png" sizes="180x180" href="misc/icon180.png?v=1731920986256">
    <link rel="icon" type="image/png" sizes="16x16" href="misc/icon16.png?v=1731920986256">
    <link rel="icon" type="image/png" sizes="32x32" href="misc/icon32.png?v=1731920986256">
    <link rel="icon" type="image/png" sizes="192x192" href="misc/icon192.png?v=1731920986256">
    <link rel="manifest" href="manifest.json?v=1731920986256">
    <meta name="msapplication-TileColor" content="#666666">
    <meta name="msapplication-config" content="browserconfig.xml">
    <link rel="preload" href="misc/icon150.png" as="image"/>
    <link rel="preload" href="locale/en.txt?v=1731920986256" as="fetch" crossorigin="anonymous"/>
    <link rel="preload" href="script.js?v=1731920986256" as="script"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/r/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/l/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/u/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/d/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/f/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/b/3/0_0.jpg?v=1731920986256"
          as="image"/>
    <meta name="description" content="Virtual Tour"/>
    <meta name="theme-color" content="#666666"/>
    <script src="script.js?v=1731920986256"></script>
    <style type="text/css">
        html,
        body {
            height: 100%;
            width: 100%;
            height: 100vh;
            width: 100vw;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        .fill-viewport {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }

        #viewer {
            z-index: 1;
        }

        #preloadContainer {
            z-index: 2;
            position: relative;
            width: 100%;
            height: 100%;
            transition: opacity 0.5s;
            -webkit-transition: opacity 0.5s;
            -moz-transition: opacity 0.5s;
            -o-transition: opacity 0.5s;
        }

        #loadingIcon {
            width: 150px;
            height: auto;
        }

        /* Small screens (phones) */
        @media (max-width: 480px) {
            #loadingIcon {
                width: 32px;
            }

            #loadingMessage {
                font-size: 14px;
            }
        }

        /* Tablets and small laptops */
        @media (min-width: 481px) and (max-width: 768px) {
            #loadingIcon {
                width: 64px;
            }

            #loadingMessage {
                font-size: 16px;
            }
        }

        /* Medium screens (normal laptops/desktops) */
        @media (min-width: 769px) and (max-width: 1200px) {
            #loadingIcon {
                width: 150px;
            }

            #loadingMessage {
                font-size: 20px;
            }
        }

        /* Large screens (big desktops, 4K displays) */
        @media (min-width: 1201px) {
            #loadingIcon {
                width: 180px;
                font-size: 24px;
            }

            #loadingMessage {
                font-size: 24px;
            }
        }
    </style>
    <link rel="stylesheet" href="fonts.css?v=1731920986256">
</head>

<body>
<div id="viewer" class="fill-viewport"></div>
<div id="preloadContainer"
     style="background: radial-gradient(circle, rgba(20, 80, 70, 0.8) 0%, #08322C 70%); display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column;">
    <img id="loadingIcon" src="/misc/icon150.png" alt="DA-CBC Logo"/>
    <span id="loadingMessage"
          style="letter-spacing: 0; color: #ffffff; font-family: Arial, Helvetica, sans-serif; text-align: center; margin-top:5px;">
            Loading virtual tour. Please wait...
        </span>
</div>

<!-- Tour Viewer -->
<div style="
    position: fixed;
    bottom: 10px;
    right: 10px;
    display: flex;
    gap: 10px;
    z-index: 9999;
    flex-wrap: wrap;
    align-items: center;
    font-family: Arial, sans-serif;
">

    <!-- Feedback -->
    <a href="https://forms.gle/3eWDkzirTS8DHLPK7" class="footer-box" style="
        background: rgba(0, 0, 0, 0.6);
        color: white;
        padding: 6px 10px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
    ">
        Feedback Form
    </a>

    <!-- Global Visit Counter -->
    <div id="visitCounter" style="
        background: rgba(0, 0, 0, 0.6);
        color: white;
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 14px;
    ">
        Visits <?php echo number_format($visitCount); ?> | Guests <?php echo number_format($uniqueVisitors); ?>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof loadTour === 'function') {
            loadTour();
        }
    });
</script>
</body>

</html>

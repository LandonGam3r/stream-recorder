<?php
/**
 * Stream Recorder™ dashboard - the control center of the recorder (see README).
 *
 * Put this file and player.html in a folder of your website (or run start-local.sh). Open the folder
 * in a browser and log in with the key from <recorder folder>/secret/dashboard_key.txt.
 * Settings: config.php next to this file (copy config.example.php) - all optional.
 */

// PHP warnings go to the server's error log, never onto a page (they could show server paths).
@ini_set('display_errors', '0');

// ============================ SETTINGS ============================
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
foreach ([
    'RECORDER_DIR' => '',     // '' = find it automatically (see config.example.php)
    'COOKIE_DAYS'  => 180,
    'IO_LIMIT_MB'  => 0,      // your host's disk IO limit in MB/s, drawn in the IO graph (0 = no line)
    'DOWNLOAD_LIMIT_MB' => 'auto', // player downloads: MB/s cap. 'auto' = 80% of IO_LIMIT_MB (none without it), 0 = none
    'MAX_FAILS'    => 10,
    'FORCE_HTTPS'  => 'auto', // 'auto' = on, except for localhost / IP addresses / local names
    'TIMEZONE'     => '',     // '' = the recorder's time zone
    'ICON_FILE'    => '',     // your own page icon (png/jpg/gif/ico), '' = built-in
] as $k => $v) {
    if (!defined($k)) define($k, $v);
}
/** The recorder folder: config, $RECORDER_DIR (start-local.sh), or next to / above this folder. */
function recorder_dir(): string
{
    $c = [RECORDER_DIR, (string)getenv('RECORDER_DIR'), __DIR__ . '/../server', dirname(__DIR__, 2) . '/twitch-recorder',
        dirname(__DIR__, 2) . '/stream-recorder', dirname(__DIR__, 2) . '/stream-recorder/server'];
    foreach ($c as $d) {
        if ($d !== '' && is_file(rtrim($d, '/') . '/record.php')) return rtrim(realpath($d) ?: $d, '/');
    }
    return '';
}
define('REC_DIR', recorder_dir());
// ==================================================================

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// Only the app's own scripts run: each page load gets a random nonce that its <script> tags carry.
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'unsafe-inline'; connect-src 'self'; media-src 'self' blob:; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), midi=(), display-capture=(), screen-wake-lock=(self)');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
/** Adds this page load's nonce to every <script> tag of a page we serve. */
function with_nonce(string $html): string
{
    return preg_replace('/<script(?=[\s>])/', '<script nonce="' . $GLOBALS['nonce'] . '"', $html);
}

$tz = TIMEZONE !== '' ? TIMEZONE : (string)(json_decode((string)@file_get_contents(REC_DIR . '/recordings/.settings.json'), true)['timezone'] ?? '');
if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);
if (REC_DIR === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Recorder folder not found. Set RECORDER_DIR in config.php next to this dashboard (see config.example.php).\n");
}

// Page icon (64x64 PNG), embedded: the page's security policy only allows images that are part of the page.
const BUILTIN_ICON = 'data:image/png;base64,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';
define('FAVICON', (function () {
    $f = ICON_FILE;
    if ($f !== '' && $f[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $f)) $f = __DIR__ . '/' . $f; // relative to this folder
    if ($f === '' || !is_file($f) || filesize($f) > 300000) return BUILTIN_ICON;
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f));
})());

function not_found(): void
{
    http_response_code(404);
    echo '<!DOCTYPE html><title>404 Not Found</title><h1>Not Found</h1>';
    exit;
}

// ---------------------------------------------------------------- HTTPS
/** HTTPS as the visitor sees it (also behind Cloudflare, which may talk plain HTTP to this server). */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return strpos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"') !== false;
}
/** Force HTTPS? 'auto': yes for real domain names, no for localhost, IP addresses and local names. */
function https_forced(): bool
{
    if (FORCE_HTTPS !== 'auto') return (bool)FORCE_HTTPS;
    $host = strtolower(preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''), '[]')));
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || strpos($host, '.') === false) return false;
    return !preg_match('/\.(local|lan|home|internal|localhost)$/', $host);
}
if (https_forced()) {
    if (!is_https()) {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') not_found();
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('Strict-Transport-Security: max-age=31536000'); // browsers then never use plain HTTP for this site
}

// ---------------------------------------------------------------- visitor address
// Behind Cloudflare every request comes from a Cloudflare server; the visitor's own address is in
// CF-Connecting-IP. That header is only trusted when the request really comes from Cloudflare.
// List as published at https://www.cloudflare.com/ips/ (checked 2026-11-25; it rarely changes).
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];
function ip_in_range(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $n = @inet_pton($net);
    if ($a === false || $n === false || strlen($a) !== strlen($n)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    if (strncmp($a, $n, $bytes) !== 0) return false;
    if ($bits % 8 === 0) return true;
    $mask = 0xFF << (8 - $bits % 8) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($n[$bytes]) & $mask);
}
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        foreach (CLOUDFLARE_RANGES as $r) if (ip_in_range($ip, $r)) return $cf;
    }
    return $ip;
}

// ---------------------------------------------------------------- access check
// The dashboard only needs the key's hash: once logged in you may delete dashboard_key.txt and stay logged in.
$hashFile = REC_DIR . '/secret/dashboard_key.hash';
$keyHash = trim((string)@file_get_contents($hashFile));
if ($keyHash === '') {
    $plain = trim((string)@file_get_contents(REC_DIR . '/secret/dashboard_key.txt'));
    $keyHash = $plain !== '' ? hash('sha256', $plain) : '';
}
if ($keyHash === '') {
    http_response_code(503);
    echo 'Dashboard not set up yet: the key is created by the next cron run of the new record.php.';
    exit;
}
// Login cookie = issue time + session generation, signed with a key derived from the dashboard key's
// hash (it never contains the key). The server rejects it after COOKIE_DAYS, and "Log out everywhere"
// raises the generation, which ends every other login at once.
$cookieName = 'recdash2';
$basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
$sessFile = REC_DIR . '/secret/dashboard_session.json';
function session_gen(): int
{
    $s = json_decode((string)@file_get_contents($GLOBALS['sessFile']), true);
    return max(1, (int)($s['gen'] ?? 1));
}
function session_cookie_value(int $gen): string
{
    $payload = time() . '.' . $gen;
    return $payload . '.' . hash_hmac('sha256', "recorder-dashboard-session|$payload", $GLOBALS['keyHash']);
}
function session_valid($v): bool
{
    if (!is_string($v) || !preg_match('/^(\d{9,12})\.(\d{1,9})\.([a-f0-9]{64})$/', $v, $m)) return false;
    if (!hash_equals(hash_hmac('sha256', "recorder-dashboard-session|{$m[1]}.{$m[2]}", $GLOBALS['keyHash']), $m[3])) return false;
    if ((int)$m[1] > time() + 300 || time() - (int)$m[1] > COOKIE_DAYS * 86400) return false;
    return (int)$m[2] === session_gen();
}
function session_set(string $value, int $expires): void
{
    setcookie($GLOBALS['cookieName'], $value, [
        'expires' => $expires, 'path' => $GLOBALS['basePath'],
        'secure' => https_forced() || is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
}
if (isset($_COOKIE['recdash'])) setcookie('recdash', '', ['expires' => 1, 'path' => $basePath]); // the old (pre-1.5) cookie

$authed = session_valid($_COOKIE[$cookieName] ?? null);

/**
 * Wrong-key counter per visitor (locked, so parallel requests can't skip counts; old entries are
 * dropped, so the file can't grow without end). $add = true counts one more failure.
 * Returns [failures this hour, minutes until it resets].
 */
function login_failures(string $ip, bool $add): array
{
    $f = REC_DIR . '/secret/dashboard_fails.json';
    $h = @fopen($f, 'c+');
    if (!$h) return [0, 0];
    flock($h, LOCK_EX);
    $list = json_decode((string)stream_get_contents($h), true) ?: [];
    $now = time();
    foreach ($list as $k => $v) if (!is_array($v) || $now - (int)($v['first'] ?? 0) > 3600) unset($list[$k]);
    if ($add) {
        $list[$ip] = ['count' => ($list[$ip]['count'] ?? 0) + 1, 'first' => $list[$ip]['first'] ?? $now];
        if (count($list) > 5000) { uasort($list, function ($a, $b) { return $b['first'] <=> $a['first']; }); $list = array_slice($list, 0, 5000, true); }
    }
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($list));
    flock($h, LOCK_UN);
    fclose($h);
    $e = $list[$ip] ?? null;
    return $e ? [(int)$e['count'], max(1, (int)ceil((3600 - ($now - $e['first'])) / 60))] : [0, 0];
}

function login_page(string $error = '', int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $err = $error !== '' ? '<p class="err" role="alert">' . htmlspecialchars($error) . '</p>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Stream Recorder™</title><link rel="icon" type="image/png" href="' . FAVICON . '">'
        . '<style>:root{--bg:#0f1115;--panel:#171a21;--line:#262a33;--text:#e6e8ec;--muted:#8b93a1;--accent:#8f7cff;--bad:#ff6b6b}'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
        . 'form{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:28px;width:min(360px,92vw);display:grid;gap:14px}'
        . 'h1{margin:0;font-size:18px;display:flex;align-items:center;gap:10px}h1 img{width:28px;height:28px;border-radius:7px}'
        . 'label{color:var(--muted);font-size:13px;display:grid;gap:6px}input{background:var(--bg);border:1px solid var(--line);color:var(--text);border-radius:8px;padding:9px 11px;font:inherit}'
        . 'input:focus{outline:none;border-color:var(--accent)}button{background:var(--accent);color:#111;border:0;border-radius:8px;padding:9px;font:inherit;font-weight:650;cursor:pointer}'
        . '.err{color:var(--bad);margin:0;font-size:13px}.hint{color:var(--muted);font-size:12px;margin:0}.u{display:none}</style></head><body>'
        . '<form method="post" action="' . htmlspecialchars($GLOBALS['basePath']) . '">'
        . '<h1><img src="' . FAVICON . '" alt="">Stream Recorder™</h1>' . $err
        . '<input class="u" type="text" name="username" value="Stream Recorder" autocomplete="username" tabindex="-1" aria-hidden="true">'
        . '<label>Dashboard key<input type="password" name="key" autocomplete="current-password" required autofocus maxlength="200"></label>'
        . '<button type="submit">Log in</button>'
        . '<p class="hint">Your browser stays logged in for ' . COOKIE_DAYS . ' days.</p>'
        . '</form></body></html>';
    exit;
}

if (!$authed) {
    $isPage = !array_intersect_key($_GET, array_flip(['api', 'io', 'file', 'play', 'delete', 'streamers', 'log', 'moments', 'upload', 'scan', 'scanapi', 'lib', 'logout', 'keyfile', 'aisummary']));
    if (isset($_GET['api']) || isset($_GET['io'])) { // the open dashboard noticed it's logged out: reload shows the login form
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"ok":false,"error":"logged out"}';
        exit;
    }
    if (!$isPage) not_found();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $ip = client_ip();
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && $origin !== 'null' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            login_page('This form must be sent from this page.', 403);
        }
        [$n, $mins] = login_failures($ip, false);
        if ($n >= MAX_FAILS) login_page("Too many wrong keys. Try again in $mins min.", 429);
        $key = $_POST['key'] ?? '';
        if (is_string($key) && strlen($key) <= 200 && hash_equals($keyHash, hash('sha256', trim($key)))) {
            session_set(session_cookie_value(session_gen()), time() + COOKIE_DAYS * 86400);
            header('Location: ' . $basePath, true, 303);
            exit;
        }
        [$n, $mins] = login_failures($ip, true);
        login_page($n >= MAX_FAILS ? "Too many wrong keys. Try again in $mins min." : 'Wrong key.', 403);
    }
    login_page();
}

// ---------------------------------------------------------------- data
function read_json(string $f): array
{
    return json_decode((string)@file_get_contents($f), true) ?: [];
}

function tail_lines(string $f, int $n, bool $fold = true): array
{
    $h = @fopen($f, 'rb');
    if (!$h) return [];
    $size = filesize($f);
    fseek($h, max(0, $size - 65536));
    $data = (string)stream_get_contents($h);
    fclose($h);
    $lines = preg_split('/\r?\n/', trim($data));
    if ($size > 65536) array_shift($lines); // first line may be cut
    // Multi-line entries (the stream summary) are folded into their first line.
    $out = [];
    foreach ($lines as $l) {
        if (!$fold || ($l !== '' && $l[0] === '[')) $out[] = $l;
        elseif ($out) $out[count($out) - 1] .= ' | ' . preg_replace('/\s+/', ' ', trim($l));
    }
    return array_slice($out, -$n);
}

function collect(): array
{
    $rec = REC_DIR . '/recordings';
    if (is_file(REC_DIR . '/summary.php')) require_once REC_DIR . '/summary.php';
    $aiSpent = 0.0;
    $convertQ = read_json("$rec/.convert_queue.json");
    $uploadQ  = read_json("$rec/.upload_queue.json");
    $now = time();
    $channels = [];
    $totalBytes = 0;

    // Streamers: everyone on the list (channels.json) plus every folder with recordings.
    $list = read_json(REC_DIR . '/channels.json');
    $names = array_keys($list);
    foreach (glob("$rec/*", GLOB_ONLYDIR) ?: [] as $d) $names[] = basename($d);
    $names = array_values(array_unique(array_filter($names, function ($n) { return is_string($n) && preg_match('/^[a-z0-9_]{1,40}$/', $n); })));
    foreach ($names as $name) {
        $chanDir = "$rec/$name";
        $st = read_json("$chanDir/state.json");
        $heartbeat = (int)($st['heartbeat'] ?? 0);
        $recording = !empty($st['stream_id']) && $now - $heartbeat < 90;
        $recordings = [];
        foreach (array_reverse(glob("$chanDir/*", GLOB_ONLYDIR) ?: []) as $d) {
            $meta = read_json("$d/meta.json");
            $files = [];
            $size = 0;
            foreach (['video.mp4', 'video.ts', 'chat.txt'] as $fn) {
                if (is_file("$d/$fn")) {
                    $s = filesize("$d/$fn");
                    $files[$fn] = $s;
                    $size += $s;
                }
            }
            $parts = glob("$d/parts/*.ts") ?: [];
            if ($parts) {
                $ps = array_sum(array_map('filesize', $parts));
                $files['video.ts (' . count($parts) . (count($parts) === 1 ? ' part)' : ' parts)')] = $ps;
                $size += $ps;
            }
            $totalBytes += $size;
            $status = 'done';
            $progress = null;
            if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) {
                $status = $recording ? 'recording' : 'interrupted';
            }
            $convert = null;
            if (isset($convertQ[$d])) {
                $cj = $convertQ[$d];
                $status = 'converting';
                $prog = @file_get_contents("$d/.convert_progress.txt", false, null, max(0, (int)@filesize("$d/.convert_progress.txt") - 4096));
                $done = 0;
                if ($prog && preg_match_all('/out_time=(\d+):(\d\d):(\d\d)/', $prog, $mm)) {
                    $i = count($mm[1]) - 1;
                    $done = $mm[1][$i] * 3600 + $mm[2][$i] * 60 + $mm[3][$i];
                }
                $total = (float)($cj['seconds'] ?? 0);
                $running = !empty($cj['started']) && $now - (int)@filemtime("$d/.convert_progress.txt") < 120;
                $elapsed = $running ? $now - (int)$cj['started'] : 0;
                $convert = [
                    'state'   => $running ? 'running' : (($cj['not_before'] ?? 0) > $now ? 'waiting for disk space' : 'queued'),
                    'pct'     => $total > 0 ? min(100, round(100 * $done / $total, 1)) : null,
                    'eta_min' => ($running && $done > 0 && $total > $done) ? round($elapsed * ($total - $done) / $done / 60) : null,
                    'attempt' => (int)($cj['tries'] ?? 0),
                    'youtube' => $cj['youtube'] ?? null,
                ];
                if (!$running) $status = 'convert queued';
            }
            if (isset($uploadQ[$d])) {
                $u = $uploadQ[$d];
                $status = $u['error'] ? 'upload waiting' : (!empty($u['session']) ? 'uploading' : 'upload queued');
                $progress = ['offset' => $u['offset'], 'size' => $u['size'], 'error' => $u['error'], 'account' => $u['account'],
                    'started' => !empty($u['session'])];
            }
            $recordings[] = [
                'folder' => basename($d), 'title' => $meta['title'] ?? '', 'started' => $meta['stream_started'] ?? null,
                'ended' => $meta['stream_ended'] ?? null, 'seconds' => $meta['recorded_seconds'] ?? null,
                'files' => $files, 'size' => $size, 'status' => $status, 'upload' => $progress,
                'youtube' => $meta['youtube'] ?? null,
                'convert' => $convert,
                'playable' => is_file("$d/video.mp4"),
                'busy' => recording_busy($d, $st, $convertQ),
                'scan' => scan_info($d),
                'ai' => ai_info($d, $aiSpent),
            ];
        }
        $channels[] = [
            'name' => $name,
            'recording' => $recording,
            'stale' => !empty($st['stream_id']) && !$recording, // live state, but no sign of life
            'title' => $st['title'] ?? null, 'game' => $st['game'] ?? null,
            'started' => isset($st['started_at']) ? date('Y-m-d H:i:s', strtotime($st['started_at'])) : null,
            'seconds' => $st['seconds'] ?? 0, 'bytes' => $st['bytes'] ?? 0, 'gaps' => $st['gaps'] ?? 0,
            'ads' => $st['ads_skipped'] ?? 0, 'chat' => $st['chat_lines'] ?? 0, 'runs' => $st['runs'] ?? 0,
            'kills' => $st['kills'] ?? 0,
            'viewer' => (function ($f) {
                $d = json_decode((string)@file_get_contents($f), true);
                if (!is_array($d)) return null;
                if (!empty($d['ad_free'])) return 'Your account (ad-free)';
                if (array_key_exists('user_id', $d) && empty($d['user_id'])) return 'Anonymous (Twitch did not recognise your login)';
                if (!empty($d['subscribed'])) return 'Anonymous (subscribed, but Twitch still shows your account ads)';
                if (isset($d['subscribed']) && $d['subscribed'] === false) return 'Anonymous (not subscribed here)';
                return 'Anonymous (your account gets ads here)';
            })("$chanDir/.auth_decision.json"),
            'heartbeat' => max($heartbeat, (int)@filemtime("$chanDir/state.json")) ?: null,
            // Routine "offline" checks are left out so the interesting lines stay visible.
            'log' => array_slice(array_values(array_filter(tail_lines("$chanDir/recorder.log", 200),
                function ($l) { return strpos($l, 'channel is offline, nothing to record') === false && strpos($l, 'Run skipped') === false; })), -25),
            'log_size' => (int)@filesize("$chanDir/recorder.log"),
            'log_old_size' => (int)@filesize("$chanDir/recorder.log.1"),
            'recordings' => array_slice($recordings, 0, 30),
            'recording_count' => count($recordings),
            'listed' => isset($list[$name]),
            'enabled' => !empty($list[$name]['enabled']),
            'youtube' => (string)($list[$name]['youtube'] ?? ''),
            'twitch_auth' => (string)($list[$name]['twitch_auth'] ?? 'auto'),
            'pc_scan' => (string)($list[$name]['pc_scan'] ?? 'off'),
            'not_found' => !empty($st['not_found']),
            'wrapping_up' => !empty($st['stream_id']) && empty($list[$name]['enabled']),
        ];
    }
    // Switched-on streamers first (live ones on top), then the rest; alphabetical within.
    usort($channels, function ($a, $b) {
        return [$b['enabled'], $b['recording'], $a['name']] <=> [$a['enabled'], $a['recording'], $b['name']];
    });

    $token = read_json(REC_DIR . '/secret/token_status.json');
    $accounts = [];
    foreach (glob(REC_DIR . '/secret/youtube_*.enc') ?: [] as $f) {
        $a = substr(basename($f, '.enc'), 8);
        $s = read_json(REC_DIR . "/secret/youtube_{$a}_status.json");
        $accounts[] = ['name' => $a, 'error' => $s['error'] ?? null];
    }
    $lastRun = tail_lines(REC_DIR . '/last_run.txt', 1, false);
    $free = @disk_free_space(REC_DIR);
    // Account quota (cPanel) is usually the real limit, not the disk.
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        $out = [];
        @exec('quota -w 2>/dev/null', $out);
        foreach ($out as $l) {
            if (preg_match('#^\s*\S+\s+(\d+)\*?\s+(\d+)\s+(\d+)#', $l, $m)) {
                $limit = (float)($m[3] > 0 ? $m[3] : $m[2]) * 1024;
                if ($limit > 0) $free = $free === false ? max(0, $limit - $m[1] * 1024) : min($free, max(0, $limit - $m[1] * 1024));
            }
        }
    }

    // What is running right now under this account (to spot leftover or doubled conversions).
    $procs = null;
    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        $out = [];
        @exec('ps -u ' . escapeshellarg((string)get_current_user()) . ' -o pid=,etimes=,pcpu=,rss=,args= 2>/dev/null', $out);
        if ($out) {
            $procs = ['convert' => [], 'other_ffmpeg' => 0, 'recorder' => 0];
            foreach ($out as $l) {
                if (!preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+(.*)$/', $l, $m)) continue;
                $args = $m[5];
                if (preg_match('#^(/bin/|/usr/bin/)?(sh|bash|nohup|setsid)\b#', $args)) continue; // wrappers
                if (stripos($args, 'ffmpeg') !== false) {
                    if (preg_match("#'?([^' ]+)/video\.part\.mp4#", $args, $f)) {
                        $procs['convert'][] = ['folder' => basename($f[1]), 'channel' => basename(dirname($f[1])),
                            'minutes' => intdiv((int)$m[2], 60), 'cpu' => (float)$m[3], 'mem_mb' => round($m[4] / 1024)];
                    } else {
                        $procs['other_ffmpeg']++;
                    }
                } elseif (strpos($args, 'record.php') !== false) {
                    $procs['recorder']++;
                }
            }
        }
    }

    return [
        'now' => $now,
        'processes' => $procs,
        'ai_summary' => ai_status($aiSpent),
        'moments' => ['rules' => array_values(array_filter((array)read_json(REC_DIR . '/moments.json'), 'is_array')), 'available' => is_file(REC_DIR . '/moments.php')],
        'channels' => $channels,
        'twitch_token' => $token ? ['status' => $token['status'] ?? '?', 'detail' => $token['detail'] ?? '', 'checked' => $token['checked'] ?? null] : null,
        'youtube_accounts' => $accounts,
        'queues' => ['convert' => count($convertQ), 'upload' => count($uploadQ)],
        'alerts' => array_slice(read_json("$rec/.notifications.json"), 0, 50),
        'disk' => ['recordings' => $totalBytes, 'free' => $free === false ? null : $free],
        'last_cron' => $lastRun[0] ?? null,
        'key_file' => is_file(REC_DIR . '/secret/dashboard_key.txt') && is_file(REC_DIR . '/secret/dashboard_key.hash'),
        'webroot_risk' => recorder_in_webroot(),
        'last_cron_age' => is_file(REC_DIR . '/last_run.txt') ? time() - filemtime(REC_DIR . '/last_run.txt') : null,
    ];
}

/** Why this recording can't be deleted right now, or null. */
function recording_busy(string $d, array $st, array $convertQ): ?string
{
    if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) return 'this stream is still being recorded (or being wrapped up)';
    if (!empty($convertQ[$d]['started'])) return 'it is being converted right now';
    return null;
}

/** Full path of a recording folder from channel + folder name, or null if it isn't one. */
function recording_dir($chan, $folder): ?string
{
    if (!is_string($chan) || !is_string($folder)) return null;
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $chan) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $folder)) return null;
    $root = realpath(REC_DIR . '/recordings');
    $d = realpath("$root/$chan/$folder");
    if (!$root || !$d || !is_dir($d) || dirname($d, 2) !== $root) return null;
    return $d;
}

if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return substr($s, $start, $len); } }

/** Content type of a file the player/scan page may fetch from a recording folder, or null. */
function file_type(string $name): ?string
{
    $fixed = ['video.mp4' => 'video/mp4', 'chat.txt' => 'text/plain; charset=utf-8', 'meta.json' => 'application/json',
              'transcript.json' => 'application/json', 'transcript.txt' => 'text/plain; charset=utf-8',
              'ai_summary.md' => 'text/plain; charset=utf-8'];
    if (isset($fixed[$name])) return $fixed[$name];
    if (preg_match('/^subtitles(\.[a-z]{2,3}(-[A-Za-z]{2,4})?)?\.vtt$/', $name)) return 'text/vtt; charset=utf-8';
    if (preg_match('/^chat_translation\.[a-z]{2,3}(-[A-Za-z]{2,4})?\.json$/', $name)) return 'application/json';
    if (preg_match('/^audio\/part\d{3}\.(ogg|m4a)$/', $name, $m)) return $m[1] === 'ogg' ? 'audio/ogg' : 'audio/mp4';
    return null;
}

/** AI summary of a recording for the dashboard, or null. Adds its cost to $spent. */
function ai_info(string $d, float &$spent): ?array
{
    if (!is_file("$d/ai_summary.json") || !function_exists('summary_load')) return null;
    $s = summary_load($d);
    $spent += (float)($s['cost_usd'] ?? 0);
    $model = (string)($s['model'] ?? '');
    return [
        'state' => (string)($s['state'] ?? ''), 'note' => $s['note'] ?? null, 'error' => $s['error'] ?? null,
        'source' => $s['source'] ?? null, 'model' => SUMMARY_MODELS[$model]['name'] ?? ($model !== '' ? $model : null),
        'cost' => isset($s['cost_usd']) ? (float)$s['cost_usd'] : null, 'finished' => (int)($s['finished'] ?? 0),
        'pictures' => $s['pictures'] ?? null, 'notes' => $s['notes'] ?? [], 'has_text' => is_file("$d/ai_summary.md"),
    ];
}

/** AI summary settings and key status for the dashboard. */
function ai_status(float $spent): array
{
    if (!function_exists('summary_settings')) return ['available' => false];
    $models = [];
    foreach (SUMMARY_MODELS as $id => $m) $models[] = ['id' => $id, 'name' => $m['name']];
    return ['available' => true, 'settings' => summary_settings(REC_DIR), 'key' => summary_key_status(REC_DIR),
        'models' => $models, 'spent' => round($spent, 4)];
}

/** Subtitle and chat-translation files of a recording: [['name' => ..., 'lang' => ..., 'kind' => 'subs'|'chat'], ...] */
function scan_outputs(string $d): array
{
    $out = [];
    $orig = (string)(read_json("$d/scan.json")['language'] ?? '');
    if (is_file("$d/subtitles.vtt")) $out[] = ['name' => 'subtitles.vtt', 'lang' => $orig, 'kind' => 'subs', 'original' => true];
    foreach (glob("$d/subtitles.*.vtt") ?: [] as $f) {
        if (preg_match('/^subtitles\.([a-z]{2,3}(?:-[A-Za-z]{2,4})?)\.vtt$/', basename($f), $m)) $out[] = ['name' => basename($f), 'lang' => $m[1], 'kind' => 'subs'];
    }
    if (is_file("$d/transcript.txt")) $out[] = ['name' => 'transcript.txt', 'lang' => '', 'kind' => 'text'];
    foreach (glob("$d/chat_translation.*.json") ?: [] as $f) {
        if (preg_match('/^chat_translation\.([a-z]{2,3}(?:-[A-Za-z]{2,4})?)\.json$/', basename($f), $m)) $out[] = ['name' => basename($f), 'lang' => $m[1], 'kind' => 'chat'];
    }
    return $out;
}

/** PC-scan state of a recording for the dashboard, or null when it was never asked/started. */
function scan_info(string $d): ?array
{
    if (!is_file("$d/scan.json")) return null;
    if (!function_exists('scan_prep_progress') && is_file(REC_DIR . '/scan.php')) require_once REC_DIR . '/scan.php';
    $s = read_json("$d/scan.json");
    $tr = is_file("$d/transcript.json") ? read_json("$d/transcript.json") : [];
    return [
        'state' => (string)($s['state'] ?? ''), 'note' => $s['note'] ?? null, 'error' => $s['error'] ?? null,
        'pieces' => (int)($s['pieces'] ?? 0), 'done' => count($tr['parts'] ?? []),
        'prep' => function_exists('scan_prep_progress') ? scan_prep_progress($d, $s) : null,
        'language' => $s['language'] ?? null, 'target' => $s['target'] ?? null,
        'segments' => (int)($s['segments'] ?? 0), 'updated' => (int)($s['updated'] ?? 0),
        'outputs' => scan_outputs($d),
    ];
}

/** Same locked read-modify-write as record.php's queue_update(). */
function dash_queue_update(string $name, callable $fn): void
{
    $f = REC_DIR . "/recordings/.{$name}_queue.json";
    if (!file_exists($f)) return;
    $h = fopen($f, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $q = json_decode((string)stream_get_contents($h), true) ?: [];
    $fn($q);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($q));
    flock($h, LOCK_UN);
    fclose($h);
}

function rrmdir(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// Token for actions that change something: only this page (not another site) can send it.
$csrf = hash_hmac('sha256', 'recorder-dashboard-actions', $keyHash);

// ---------------------------------------------------------------- recording files (player)
// Video is sent in pieces of at most 8 MB per request: the browser simply asks for the next piece.
// One long PHP request per viewing would risk being stopped by the host (like long cron runs).
if (isset($_GET['file'])) {
    $name = $_GET['name'] ?? '';
    $d = recording_dir($_GET['ch'] ?? null, $_GET['file']);
    $type = is_string($name) ? file_type($name) : null;
    if (!$d || !$type || !is_file("$d/$name")) not_found();
    $path = "$d/$name";
    @set_time_limit(isset($_SERVER['HTTP_RANGE']) ? 120 : 0);
    while (ob_get_level()) ob_end_clean();
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $partial = false;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start >= $size || $start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $partial = true;
    }
    if ($name === 'video.mp4' && $partial && !isset($_GET['dl'])) { // players always ask with a Range; a plain download gets it all
        $end = min($end, $start + 8 * 1048576 - 1);
    }
    // "Download to this PC" is running: a YouTube upload waits meanwhile (they'd share the host's IO limit).
    if ($name === 'video.mp4' && isset($_GET['save'])) {
        $mark = REC_DIR . '/recordings/.pc_download';
        if (!is_file($mark) || time() - (int)filemtime($mark) > 15) @touch($mark);
    }
    header('Content-Type: ' . $type);
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
    if (isset($_GET['dl'])) {
        $fn = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename(dirname($d)) . '_' . basename($d) . '_' . basename($name));
        header('Content-Disposition: attachment; filename="' . $fn . '"');
    }
    // Video may stay in the browser's cache briefly for smooth seeking; text (chat, subtitles) never.
    header($name === 'video.mp4' ? 'Cache-Control: private, max-age=600' : 'Cache-Control: no-store');
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $h = fopen($path, 'rb');
    fseek($h, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($h) && !connection_aborted()) {
        $buf = fread($h, min(262144, $left));
        if ($buf === '' || $buf === false) break;
        echo $buf;
        flush();
        $left -= strlen($buf);
    }
    fclose($h);
    exit;
}

// ---------------------------------------------------------------- player page
if (isset($_GET['play'])) {
    $d = recording_dir($_GET['ch'] ?? null, $_GET['play']);
    $player = @file_get_contents(__DIR__ . '/player.html');
    if (!$d || !is_file("$d/video.mp4")) not_found();
    if ($player === false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'player.html is missing: upload it next to this index.php (same file as pc-tools/player/player.html).';
        exit;
    }
    $base = strtok($_SERVER['REQUEST_URI'], '?') . '?ch=' . rawurlencode($_GET['ch']) . '&file=' . rawurlencode($_GET['play']) . '&name=';
    $src = [
        'video' => $base . 'video.mp4',
        'chat'  => is_file("$d/chat.txt") ? $base . 'chat.txt' : null,
        'meta'  => is_file("$d/meta.json") ? $base . 'meta.json' : null,
        'name'  => basename($d),
        'size'  => filesize("$d/video.mp4"),
        'channel' => (string)$_GET['ch'],
        'title' => (string)(read_json("$d/meta.json")['title'] ?? ''),
        'me'    => (string)(read_json(REC_DIR . '/secret/token_status.json')['login'] ?? ''), // your Twitch name, highlighted in chat
        'dl_limit_mb' => DOWNLOAD_LIMIT_MB === 'auto' ? round(0.8 * (float)IO_LIMIT_MB, 1) : (float)DOWNLOAD_LIMIT_MB,
        // From the PC scan: subtitles (original + translations) and translated chat.
        'extras' => array_map(function ($o) use ($base) { return $o + ['url' => $base . rawurlencode($o['name'])]; }, scan_outputs($d)),
    ];
    $inject = '<script>window.PLAYER_SOURCE = ' . json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';</script>';
    $pos = strpos($player, '<script>');
    header('Content-Type: text/html; charset=utf-8');
    $player = preg_replace('#</head>#', '<link rel="icon" type="image/png" href="' . FAVICON . '">' . "\n</head>", $player, 1);
    $pos = strpos($player, '<script>');
    echo with_nonce($pos === false ? $player : substr($player, 0, $pos) . $inject . substr($player, $pos));
    exit;
}

// ---------------------------------------------------------------- PC scan page
// Runs on your PC, in the browser: downloads the sound pieces, recognises speech, translates, and sends
// the text back via ?scanapi. Models come from Hugging Face (cached by the browser after the first time).
/** The scan page's own key: works only for that recording's scan steps, for 24 hours (not the dashboard's action token). */
function scan_key(string $ch, string $folder, ?int $exp = null): string
{
    $exp = $exp ?? time() + 86400;
    return $exp . '.' . hash_hmac('sha256', "scan|$ch|$folder|$exp", $GLOBALS['keyHash']);
}
function scan_key_valid($key, string $ch, string $folder): bool
{
    if (!is_string($key) || !preg_match('/^(\d{9,12})\.[a-f0-9]{64}$/', $key, $m) || (int)$m[1] < time()) return false;
    return hash_equals(scan_key($ch, $folder, (int)$m[1]), $key);
}

// ---------------------------------------------------------------- speech library files (pinned copy, see scan.php)
if (isset($_GET['lib'])) {
    if (!is_file(REC_DIR . '/scan.php')) not_found();
    require_once REC_DIR . '/scan.php';
    $m = speech_lib_manifest(REC_DIR);
    $name = (string)$_GET['lib'];
    if (!$m || !isset($m['files'][$name]) || !preg_match('/^[\w.-]+\.(js|mjs|wasm)$/', $name, $x)) not_found();
    $path = speech_lib_dir(REC_DIR) . "/$name";
    header('Content-Type: ' . ($x[1] === 'wasm' ? 'application/wasm' : 'text/javascript; charset=utf-8'));
    header('Cache-Control: private, max-age=604800, immutable');
    header('Content-Length: ' . filesize($path));
    @set_time_limit(120);
    while (ob_get_level()) ob_end_clean();
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- PC scan page
// Runs on your PC, in the browser: downloads the sound pieces, recognises speech, translates, and sends
// the text back via ?scanapi. The speech library comes from this server (a pinned copy); only the
// speech models come from Hugging Face (data, cached by the browser after the first time).
if (isset($_GET['scan'])) {
    $d = recording_dir($_GET['ch'] ?? null, $_GET['scan']);
    if (!$d) not_found();
    $page = @file_get_contents(__DIR__ . '/scan.html');
    if ($page === false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'scan.html is missing: upload it next to this index.php.';
        exit;
    }
    $lib = null;
    if (is_file(REC_DIR . '/scan.php')) {
        require_once REC_DIR . '/scan.php';
        $lib = speech_lib_manifest(REC_DIR);
        if (!$lib) speech_lib_request(REC_DIR); // the next cron run downloads it (once)
    }
    $meta = read_json("$d/meta.json");
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    $ch = (string)$_GET['ch'];
    $src = [
        'channel' => $ch, 'folder' => basename($d),
        'title' => (string)($meta['title'] ?? ''), 'started' => (string)($meta['stream_started'] ?? ''),
        'seconds' => (float)($meta['recorded_seconds'] ?? 0),
        'api' => $self . '?scanapi=1',
        'files' => $self . '?ch=' . rawurlencode($ch) . '&file=' . rawurlencode(basename($d)) . '&name=',
        'player' => is_file("$d/video.mp4") ? $self . '?ch=' . rawurlencode($ch) . '&play=' . rawurlencode(basename($d)) : null,
        'key' => scan_key($ch, basename($d)),
        'has_chat' => is_file("$d/chat.txt"),
        'lib' => $lib ? $self . '?lib=' : null, // null = the server is still downloading it
        // AI summary: the page can write one with Chrome's built-in AI (free); "summary_only" = only that step.
        'summary' => is_file(REC_DIR . '/summary.php') ? [
            'server' => is_file(REC_DIR . '/secret/anthropic.enc') || is_file(REC_DIR . '/secret/anthropic_setup.txt'),
            'only' => isset($_GET['summary']),
            'has_transcript' => is_file("$d/transcript.json"),
        ] : null,
    ];
    // Import map: the browser refuses a library file whose checksum differs from the one recorded at download.
    $importMap = '';
    if ($lib) {
        $integrity = [];
        foreach ($lib['files'] as $name => $f) {
            if (preg_match('/\.(m?js)$/', $name)) $integrity[$self . '?lib=' . $name] = 'sha256-' . $f['sha256'];
        }
        $importMap = '<script type="importmap">' . json_encode(['imports' => (object)[], 'integrity' => $integrity], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
    }
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$GLOBALS['nonce']}' 'strict-dynamic' 'wasm-unsafe-eval'; "
        . "style-src 'unsafe-inline'; connect-src 'self' https://huggingface.co https://*.huggingface.co https://*.hf.co; "
        . "media-src 'self' blob: data:; img-src data:; worker-src 'self' blob:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=utf-8');
    $inject = $importMap . '<script>window.SCAN_SOURCE = ' . json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
    $page = preg_replace('#</head>#', '<link rel="icon" type="image/png" href="' . FAVICON . '">' . "\n</head>", $page, 1);
    $pos = strpos($page, '<script');
    echo with_nonce($pos === false ? $page : substr($page, 0, $pos) . $inject . substr($page, $pos));
    exit;
}

// ---------------------------------------------------------------- PC scan actions
if (isset($_GET['scanapi'])) {
    // From the dashboard: its action token. From the scan page: the scan-only key for that one recording.
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') action_fail(405, 'POST only');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) action_fail(403, 'Wrong origin');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $viaDash = hash_equals($csrf, (string)($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ''));
    if (!$viaDash && !scan_key_valid($_SERVER['HTTP_X_SCAN_KEY'] ?? null, (string)($in['channel'] ?? ''), (string)($in['folder'] ?? ''))) {
        action_fail(403, 'This scan page has expired - open it again from the dashboard.');
    }
    if (!is_file(REC_DIR . '/scan.php')) action_fail(409, 'scan.php is missing in the recorder folder: upload it next to record.php.');
    require_once REC_DIR . '/scan.php';
    $op = (string)($in['op'] ?? '');
    $d = recording_dir($in['channel'] ?? null, $in['folder'] ?? null);
    if (!$d) action_fail(404, 'That recording no longer exists.');
    $chanDir = dirname($d);
    $logLine = function (string $msg) use ($chanDir) {
        @file_put_contents("$chanDir/recorder.log", '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND | LOCK_EX);
    };
    $lang = function ($v) { $v = (string)$v; return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $v) ? $v : ''; };
    $state = (string)(scan_load($d)['state'] ?? '');
    $active = in_array($state, ['requested', 'preparing', 'ready', 'scanning'], true);

    if ($op === 'status') {
        $s = scan_load($d);
        $tr = is_file("$d/transcript.json") ? read_json("$d/transcript.json") : [];
        $libOk = (bool)speech_lib_manifest(REC_DIR);
        if (!$libOk && !empty($in['want_lib'])) speech_lib_request(REC_DIR);
        echo json_encode(['ok' => true, 'scan' => $s, 'pieces' => scan_audio_parts($d), 'prep' => scan_prep_progress($d, $s), 'now' => time(), 'lib' => $libOk,
            'transcript' => ['parts' => $tr['parts'] ?? (object)[], 'language' => $tr['language'] ?? null,
                'target' => $tr['target'] ?? null, 'model' => $tr['model'] ?? null],
            'outputs' => scan_outputs($d)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($op === 'request') {
        $st = read_json("$chanDir/state.json");
        if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) action_fail(409, 'This stream is still being recorded.');
        if (!is_file("$d/video.mp4") && !is_file("$d/video.ts") && !glob("$d/parts/*.ts")) action_fail(409, 'This recording has no video.');
        if (!$active) {
            $ready = scan_audio_parts($d) && trim((string)@file_get_contents("$d/audio/.exit")) === '0';
            scan_update($d, function (array &$s) use ($ready) {
                $keep = array_intersect_key($s, array_flip(['asked']));
                $s = $keep + ['state' => $ready ? 'ready' : 'requested', 'requested' => time(), 'updated' => time()];
            });
            $logLine('PC scan requested via the dashboard: ' . basename($d) . ($ready ? ' (sound already prepared)' : ' (the server prepares the sound next run)'));
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'skip' || $op === 'cancel') {
        if ($op === 'skip' && $state !== 'ask') action_fail(409, 'Nothing to skip.');
        if ($op === 'cancel' && !$active) action_fail(409, 'No scan is running.');
        scan_update($d, function (array &$s) use ($op) { $s['state'] = $op === 'skip' ? 'skipped' : 'cancelled'; $s['updated'] = time(); });
        $logLine('PC scan ' . ($op === 'skip' ? 'skipped' : 'cancelled') . ' via the dashboard: ' . basename($d) . ' - a YouTube upload goes ahead without it');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'fail') {
        if ($active) {
            $why = mb_substr(trim((string)($in['error'] ?? 'unknown problem')), 0, 300);
            scan_update($d, function (array &$s) use ($why) { $s['state'] = 'failed'; $s['error'] = $why; $s['updated'] = time(); });
            $logLine('PC scan failed on the PC for ' . basename($d) . ": $why");
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'save') {
        // One sound piece done: its subtitle lines [[start, end, text, translation?], ...] (times in the video).
        if (!in_array($state, ['ready', 'scanning', 'done'], true)) action_fail(409, 'This scan was cancelled or not started.');
        $part = (int)($in['part'] ?? -1);
        $segs = $in['segments'] ?? null;
        if ($part < 0 || $part > 999 || !is_array($segs) || count($segs) > 5000) action_fail(400, 'Bad data.');
        $clean = [];
        foreach ($segs as $g) {
            if (!is_array($g) || !is_numeric($g[0] ?? null) || !is_numeric($g[1] ?? null)) continue;
            $t = mb_substr(trim((string)($g[2] ?? '')), 0, 500);
            if ($t === '') continue;
            $row = [round((float)$g[0], 2), round((float)$g[1], 2), $t];
            if (isset($g[3]) && trim((string)$g[3]) !== '') $row[] = mb_substr(trim((string)$g[3]), 0, 700);
            $clean[] = $row;
        }
        $f = "$d/transcript.json";
        $h = fopen($f, 'c+');
        flock($h, LOCK_EX);
        $tr = json_decode((string)stream_get_contents($h), true) ?: [];
        $tr['parts'] = $tr['parts'] ?? [];
        $tr['parts'][(string)$part] = $clean;
        foreach (['language', 'target', 'model'] as $k) if (isset($in[$k])) $tr[$k] = $k === 'model' ? preg_replace('/[^A-Za-z0-9_.\/-]/', '', (string)$in[$k]) : $lang($in[$k]);
        ksort($tr['parts'], SORT_NUMERIC);
        $tr['segments'] = array_merge(...array_values($tr['parts']) ?: [[]]);
        $tr['updated'] = time();
        ftruncate($h, 0); rewind($h);
        fwrite($h, json_encode($tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flock($h, LOCK_UN); fclose($h);
        if ($state !== 'done') scan_update($d, function (array &$s) { $s['state'] = 'scanning'; $s['updated'] = time(); });
        echo json_encode(['ok' => true, 'parts' => count($tr['parts'])]);
        exit;
    }
    if ($op === 'chat') {
        // Translated chat: {original message text: translation}, for the player's "Translated" switch.
        $target = $lang($in['target'] ?? '');
        $map = $in['map'] ?? null;
        if ($target === '' || !is_array($map) || count($map) > 200000) action_fail(400, 'Bad data.');
        $clean = [];
        foreach ($map as $k => $v) {
            if (!is_string($k) || !is_string($v) || $v === '' || $v === $k) continue;
            $clean[mb_substr($k, 0, 600)] = mb_substr($v, 0, 900);
        }
        $json = json_encode(['target' => $target, 'map' => (object)$clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 20 * 1048576) action_fail(413, 'Translated chat is too large.');
        file_put_contents("$d/chat_translation.$target.json", $json, LOCK_EX);
        echo json_encode(['ok' => true, 'count' => count($clean)]);
        exit;
    }
    if ($op === 'text') {
        // Readable version: what the streamer said and the chat, in order, with translations.
        $text = (string)($in['text'] ?? '');
        if ($text === '' || strlen($text) > 30 * 1048576) action_fail(400, 'Bad data.');
        file_put_contents("$d/transcript.txt", $text, LOCK_EX);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'summary') {
        // Summary written on the PC with Chrome's built-in AI (when no API key is set, or by choice).
        if (!is_file(REC_DIR . '/summary.php')) action_fail(409, 'summary.php is missing in the recorder folder.');
        require_once REC_DIR . '/summary.php';
        $text = str_replace("\r", '', (string)($in['text'] ?? ''));
        if (trim($text) === '' || strlen($text) > 300000) action_fail(400, 'Bad data.');
        if (in_array((string)(summary_load($d)['state'] ?? ''), SUMMARY_ACTIVE, true)) action_fail(409, 'Claude is making a summary of this stream right now.');
        file_put_contents("$d/ai_summary.md", rtrim($text) . "\n", LOCK_EX);
        summary_update($d, function (array &$s) {
            $s = ['state' => 'done', 'source' => 'chrome', 'model' => 'chrome-built-in', 'finished' => time(), 'updated' => time(), 'cost_usd' => 0];
        });
        $logLine('AI summary written on the PC (Chrome\'s built-in AI) for ' . basename($d));
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'finish') {
        if (!in_array($state, ['ready', 'scanning', 'done'], true)) action_fail(409, 'This scan was cancelled or not started.');
        $tr = read_json("$d/transcript.json");
        $segs = $tr['segments'] ?? [];
        file_put_contents("$d/subtitles.vtt", scan_vtt($segs, 2));
        $target = (string)($tr['target'] ?? '');
        $translated = 0;
        foreach ($segs as $g) if (isset($g[3])) $translated++;
        if ($target !== '' && $translated) file_put_contents("$d/subtitles.$target.vtt", scan_vtt($segs, 3));
        scan_update($d, function (array &$s) use ($tr, $segs, $translated) {
            $s['state'] = 'done'; $s['updated'] = time(); $s['finished'] = time(); $s['moments_pending'] = true;
            $s['segments'] = count($segs); $s['language'] = $tr['language'] ?? null; $s['target'] = $translated ? ($tr['target'] ?? null) : null;
            unset($s['error'], $s['note']);
        });
        // The sound pieces were only needed for the scan.
        foreach (glob("$d/audio/{*,.*}", GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir("$d/audio");
        $logLine(sprintf('PC scan finished for %s: %d subtitle lines%s', basename($d), count($segs),
            $translated ? ", translated to $target" : '') . ' - moments are updated at the next cron run');
        echo json_encode(['ok' => true]);
        exit;
    }
    action_fail(400, 'Unknown action.');
}

// ---------------------------------------------------------------- full log of a streamer (or the system log)
if (isset($_GET['log'])) {
    $ch = (string)$_GET['log'];
    if ($ch === '_system') $f = REC_DIR . '/recordings/.system.log';
    elseif (preg_match('/^[a-z0-9_]{1,40}$/', $ch)) $f = REC_DIR . "/recordings/$ch/recorder.log";
    else not_found();
    if (!empty($_GET['old'])) $f .= '.1'; // the part before the last rotation
    if (!is_file($f)) not_found();
    $quiet = !empty($_GET['quiet']); // leave out the routine "offline" / "run skipped" lines
    header('Content-Type: text/plain; charset=utf-8');
    if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . ($ch === '_system' ? 'system' : $ch) . '-recorder' . (!empty($_GET['old']) ? '-older' : '') . '.log"');
    @set_time_limit(60);
    while (ob_get_level()) ob_end_clean();
    $h = fopen($f, 'rb');
    while (($l = fgets($h)) !== false) {
        if ($quiet && (strpos($l, 'channel is offline, nothing to record') !== false || strpos($l, 'Run skipped') !== false)) continue;
        echo $l;
    }
    fclose($h);
    exit;
}

// ---------------------------------------------------------------- IO history (graph)
if (isset($_GET['io'])) {
    header('Content-Type: application/json; charset=utf-8');
    $h = read_json(REC_DIR . '/recordings/.io_history.json');
    $rows = [];
    foreach ($h as $t => $v) $rows[] = [(int)$t, (int)$v[2], (int)$v[3], (int)$v[4]]; // minute, convert, record, other (bytes)
    usort($rows, function ($a, $b) { return $a[0] <=> $b[0]; });
    echo json_encode(['now' => time(), 'limit_mb' => IO_LIMIT_MB, 'rows' => $rows]);
    exit;
}

/** Locked read-modify-write of channels.json, written atomically (record.php does the same). */
function channels_update(callable $fn): void
{
    $f = REC_DIR . '/channels.json';
    $lk = fopen("$f.lock", 'c');
    if (!$lk) throw new RuntimeException('cannot open channels.json.lock');
    flock($lk, LOCK_EX);
    $list = json_decode((string)@file_get_contents($f), true);
    if (!is_array($list)) $list = [];
    $fn($list);
    if (file_put_contents("$f.tmp", json_encode($list, JSON_PRETTY_PRINT)) === false || !rename("$f.tmp", $f)) {
        flock($lk, LOCK_UN);
        throw new RuntimeException('could not save channels.json (file permissions?)');
    }
    flock($lk, LOCK_UN);
    fclose($lk);
}

/** Is the recorder folder inside this website's folder (where its files might be downloadable)? */
function recorder_in_webroot(): bool
{
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $rec = realpath(REC_DIR);
    if (!$root || !$rec) return false;
    return strpos(rtrim($rec, '/') . '/', rtrim($root, '/') . '/') === 0;
}

function check_action_request(): array
{
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') action_fail(405, 'POST only');
    if (!hash_equals($GLOBALS['csrf'], (string)($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ''))) action_fail(403, 'Reload the page and try again.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) action_fail(403, 'Wrong origin');
    return json_decode((string)file_get_contents('php://input'), true) ?: [];
}
function action_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ---------------------------------------------------------------- log out (this browser, or everywhere)
if (isset($_GET['logout'])) {
    $in = check_action_request();
    if (($in['op'] ?? '') === 'all') {
        $gen = session_gen() + 1;
        @file_put_contents($GLOBALS['sessFile'], json_encode(['gen' => $gen, 'changed' => time()]), LOCK_EX);
        @chmod($GLOBALS['sessFile'], 0600);
        @file_put_contents(REC_DIR . '/recordings/.system.log', '[' . date('Y-m-d H:i:s') . "] Dashboard: logged out everywhere (all logins ended)\n", FILE_APPEND | LOCK_EX);
    }
    session_set('', 1);
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- delete the plain-text key copy
if (isset($_GET['keyfile'])) {
    $in = check_action_request();
    $txt = REC_DIR . '/secret/dashboard_key.txt';
    if (!is_file(REC_DIR . '/secret/dashboard_key.hash')) action_fail(409, 'The key\'s hash file is missing - keep the plain copy for now.');
    if (is_file($txt)) {
        @file_put_contents($txt, str_repeat('x', (int)filesize($txt)));
        @unlink($txt);
    }
    if (is_file($txt)) action_fail(500, 'Could not delete secret/dashboard_key.txt - delete it by hand.');
    @file_put_contents(REC_DIR . '/recordings/.system.log', '[' . date('Y-m-d H:i:s') . "] Dashboard: plain-text key copy deleted (only its hash remains)\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- AI summary: settings, API key, make / delete
if (isset($_GET['aisummary'])) {
    $in = check_action_request();
    if (!is_file(REC_DIR . '/summary.php')) action_fail(409, 'summary.php is missing in the recorder folder: upload it next to record.php.');
    require_once REC_DIR . '/summary.php';
    $op = (string)($in['op'] ?? '');
    $sec = REC_DIR . '/secret';
    $sysLog = function (string $m) { @file_put_contents(REC_DIR . '/recordings/.system.log', '[' . date('Y-m-d H:i:s') . "] Dashboard: $m\n", FILE_APPEND | LOCK_EX); };
    if ($op === 'settings') {
        $cur = summary_settings(REC_DIR);
        foreach (['auto', 'pictures'] as $k) if (array_key_exists($k, $in)) $cur[$k] = (bool)$in[$k];
        if (isset($in['model'])) {
            if (!isset(SUMMARY_MODELS[(string)$in['model']])) action_fail(400, 'Unknown model.');
            $cur['model'] = (string)$in['model'];
        }
        file_put_contents(REC_DIR . '/summary_settings.json', json_encode($cur), LOCK_EX);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'key') {
        // The dashboard can't encrypt (only the cron job has REC_KEY): it leaves the key for the next cron run,
        // which encrypts it into secret/anthropic.enc and deletes this file - like the Twitch token.
        $k = trim((string)($in['key'] ?? ''));
        if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,300}$/', $k)) action_fail(400, 'That doesn\'t look like an Anthropic API key - it starts with sk-ant-.');
        if (!is_dir($sec)) action_fail(409, 'The secret/ folder is missing - has the recorder run yet?');
        $tmp = "$sec/anthropic_setup.txt.tmp";
        if (file_put_contents($tmp, $k) === false) action_fail(500, 'Could not save the key.');
        @chmod($tmp, 0600);
        rename($tmp, "$sec/anthropic_setup.txt");
        @unlink("$sec/anthropic_status.json");
        $sysLog('Anthropic API key entered - the next cron run stores it encrypted');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'key_remove') {
        foreach (['anthropic_setup.txt', 'anthropic.enc', 'anthropic_status.json'] as $f) {
            if (is_file("$sec/$f")) { @file_put_contents("$sec/$f", str_repeat('x', (int)filesize("$sec/$f"))); @unlink("$sec/$f"); }
        }
        $sysLog('Anthropic API key removed');
        echo json_encode(['ok' => true]);
        exit;
    }
    $d = recording_dir($in['channel'] ?? null, $in['folder'] ?? null);
    if (!$d) action_fail(404, 'That recording no longer exists.');
    $chanLog = function (string $m) use ($d) { @file_put_contents(dirname($d) . '/recorder.log', '[' . date('Y-m-d H:i:s') . "] $m\n", FILE_APPEND | LOCK_EX); };
    $state = (string)(summary_load($d)['state'] ?? '');
    if ($op === 'queue') {
        if (!is_file("$sec/anthropic.enc") && !is_file("$sec/anthropic_setup.txt")) action_fail(409, 'No API key yet - set one in the AI summary card, or use "Summarize on this PC".');
        if (in_array($state, SUMMARY_ACTIVE, true)) action_fail(409, 'A summary of this stream is already being made.');
        $st = read_json(dirname($d) . '/state.json');
        if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) action_fail(409, 'This stream is still being recorded - the summary can be made when it has ended.');
        summary_queue($d, 'from the dashboard');
        $chanLog('AI summary requested via the dashboard: ' . basename($d) . ' - made by the next cron runs');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'cancel') {
        if (!in_array($state, SUMMARY_ACTIVE, true)) action_fail(409, 'No summary is being made.');
        summary_update($d, function (array &$s) { $s['state'] = 'cancelled'; $s['updated'] = time(); unset($s['note']); });
        $chanLog('AI summary cancelled via the dashboard: ' . basename($d));
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op === 'delete') {
        if (in_array($state, SUMMARY_ACTIVE, true)) action_fail(409, 'Cancel the summary that is being made first.');
        @unlink("$d/ai_summary.md");
        @unlink("$d/ai_summary.json");
        echo json_encode(['ok' => true]);
        exit;
    }
    action_fail(400, 'Unknown action.');
}

// ---------------------------------------------------------------- streamers: add / switch / settings / remove
if (isset($_GET['streamers'])) {
    $in = check_action_request();
    $op = (string)($in['op'] ?? '');
    $ch = strtolower(trim((string)($in['channel'] ?? '')));
    if (!preg_match('/^[a-z0-9_]{3,25}$/', $ch)) action_fail(400, 'A Twitch name is 3-25 letters, digits or _ (the part after twitch.tv/).');
    $accounts = array_map(function ($f) { return substr(basename($f, '.enc'), 8); }, glob(REC_DIR . '/secret/youtube_*.enc') ?: []);
    $st = read_json(REC_DIR . "/recordings/$ch/state.json");
    $err = null;
    try {
        channels_update(function (array &$list) use ($op, $ch, $in, $accounts, $st, &$err) {
            $have = isset($list[$ch]) || is_dir(REC_DIR . "/recordings/$ch");
            if ($op === 'add') {
                if (isset($list[$ch])) { $err = "$ch is already on the list."; return; }
                $list[$ch] = ['enabled' => !empty($in['enabled']), 'youtube' => '', 'twitch_auth' => 'auto', 'added' => time()];
            } elseif ($op === 'set') {
                if (!$have) { $err = "$ch is not on the list."; return; }
                $c = $list[$ch] ?? ['enabled' => false, 'youtube' => '', 'twitch_auth' => 'auto', 'added' => time()];
                if (array_key_exists('enabled', $in)) $c['enabled'] = (bool)$in['enabled'];
                if (array_key_exists('youtube', $in)) {
                    $y = (string)$in['youtube'];
                    if ($y !== '' && !in_array($y, $accounts, true)) { $err = "No YouTube account named '$y' is set up."; return; }
                    $c['youtube'] = $y;
                }
                if (array_key_exists('twitch_auth', $in)) {
                    if (!in_array($in['twitch_auth'], ['auto', 'on', 'off'], true)) { $err = 'Unknown setting.'; return; }
                    $c['twitch_auth'] = $in['twitch_auth'];
                }
                if (array_key_exists('pc_scan', $in)) {
                    if (!in_array($in['pc_scan'], ['ask', 'off'], true)) { $err = 'Unknown setting.'; return; }
                    $c['pc_scan'] = $in['pc_scan'];
                }
                $c['changed'] = time();
                $list[$ch] = $c;
            } elseif ($op === 'remove') {
                if (!empty($list[$ch]['enabled'])) { $err = 'Switch recording off first.'; return; }
                if (!empty($st['stream_id'])) { $err = 'Its last recording is still being wrapped up - try again in a minute.'; return; }
                unset($list[$ch]);
            } else {
                $err = 'Unknown action.';
            }
        });
    } catch (Throwable $e) {
        action_fail(500, $e->getMessage());
    }
    if ($err) action_fail(409, $err);
    // Removed and no recordings left: drop its folder too (only the log and state are in it).
    $cd = REC_DIR . "/recordings/$ch";
    if ($op === 'remove' && is_dir($cd) && !glob("$cd/*", GLOB_ONLYDIR)) { rrmdir($cd); echo json_encode(['ok' => true]); exit; }
    $what = ['add' => !empty($in['enabled']) ? 'added and switched on' : 'added (switched off)', 'remove' => 'removed from the list',
        'set' => isset($in['enabled']) ? ($in['enabled'] ? 'recording switched ON' : 'recording switched OFF')
            : (isset($in['youtube']) ? 'YouTube upload set to ' . ($in['youtube'] ?: 'none')
            : (isset($in['pc_scan']) ? 'PC scan after each stream: ' . ($in['pc_scan'] === 'ask' ? 'ask' : 'off')
            : 'Twitch login set to ' . ($in['twitch_auth'] ?? '')))][$op];
    if (is_dir(REC_DIR . "/recordings/$ch")) {
        @file_put_contents(REC_DIR . "/recordings/$ch/recorder.log", '[' . date('Y-m-d H:i:s') . "] Dashboard: $what\n", FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- YouTube moments rules (+ test on a recording)
if (isset($_GET['moments'])) {
    $in = check_action_request();
    $file = REC_DIR . '/moments.json';
    if (!is_file(REC_DIR . '/moments.php')) action_fail(409, 'moments.php is missing in the recorder folder: upload it next to record.php.');
    require_once REC_DIR . '/moments.php';
    $op = (string)($in['op'] ?? '');
    $update = function (callable $fn) use ($file) {
        $lk = fopen("$file.lock", 'c');
        flock($lk, LOCK_EX);
        $rules = json_decode((string)@file_get_contents($file), true);
        if (!is_array($rules)) $rules = [];
        $err = $fn($rules);
        if ($err === null) {
            if (file_put_contents("$file.tmp", json_encode(array_values($rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false || !rename("$file.tmp", $file)) $err = 'could not save moments.json';
        }
        flock($lk, LOCK_UN);
        fclose($lk);
        if ($err !== null) action_fail(409, $err);
    };
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
    if ($op === 'add') {
        $r = [
            'id' => bin2hex(random_bytes(6)), 'enabled' => true,
            'channel' => strtolower(trim((string)($in['channel'] ?? ''))),
            'type' => (string)($in['type'] ?? ''), 'value' => trim((string)($in['value'] ?? '')),
            'count' => (int)($in['count'] ?? 10), 'window' => (int)($in['window'] ?? 30),
            'label' => trim((string)($in['label'] ?? '')), 'title' => !empty($in['title']),
        ];
        if ($r['channel'] !== '' && !preg_match('/^[a-z0-9_]{1,40}$/', $r['channel'])) action_fail(400, 'Unknown streamer.');
        if (!in_array($r['type'], MOMENT_TYPES, true)) action_fail(400, 'Unknown rule type.');
        if ($r['type'] !== 'burst' && $r['value'] === '') action_fail(400, 'Fill in what to look for.');
        if ($len($r['value']) > 100) action_fail(400, 'What to look for: at most 100 characters.');
        if ($r['label'] === '' || $len($r['label']) > 40) action_fail(400, 'The label is 1-40 characters (it goes into the YouTube title/description).');
        if ($r['type'] === 'burst' && ($r['count'] < 2 || $r['count'] > 1000 || $r['window'] < 5 || $r['window'] > 3600)) action_fail(400, 'Burst: 2-1000 messages within 5-3600 seconds.');
        if ($r['type'] !== 'burst') unset($r['count'], $r['window']);
        $update(function (array &$rules) use ($r) { if (count($rules) >= 50) return 'At most 50 rules.'; $rules[] = $r; return null; });
    } elseif ($op === 'delete' || $op === 'toggle') {
        $id = (string)($in['id'] ?? '');
        $update(function (array &$rules) use ($op, $id, $in) {
            foreach ($rules as $i => $r) {
                if (($r['id'] ?? '') !== $id) continue;
                if ($op === 'delete') unset($rules[$i]); else $rules[$i]['enabled'] = !empty($in['enabled']);
                return null;
            }
            return 'That rule no longer exists.';
        });
    } elseif ($op === 'test') {
        $d = recording_dir($in['channel'] ?? null, $in['folder'] ?? null);
        if (!$d) action_fail(404, 'That recording no longer exists.');
        $res = moments_scan($d, moments_rules($file), basename(dirname($d)));
        $meta = read_json("$d/meta.json");
        $base = ($meta['channel'] ?? basename(dirname($d))) . ' - ' . (($meta['title'] ?? '') !== '' ? $meta['title'] : 'stream')
            . ' (' . substr((string)($meta['stream_started'] ?? ''), 0, 10) . ')';
        echo json_encode(['ok' => true, 'count' => count($res['moments']),
            'moments' => array_map(function ($m) { return ['clock' => moments_clock($m[0]), 't' => $m[0], 'label' => $m[1], 'detail' => $m[2]]; }, array_slice($res['moments'], 0, 200)),
            'title' => moments_title(str_replace(['<', '>'], '', $base), $res['labels']),
            'description' => moments_description($res['moments'])], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        action_fail(400, 'Unknown action.');
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------------------------------------------------------------- delete a recording
if (isset($_GET['delete'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'POST only');
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ''))) $fail(403, 'Reload the page and try again.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) $fail(403, 'Wrong origin');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $d = recording_dir($in['channel'] ?? null, $in['folder'] ?? null);
    if (!$d) $fail(404, 'That recording no longer exists.');
    $chanDir = dirname($d);
    $st = read_json("$chanDir/state.json");
    $convertQ = read_json(REC_DIR . '/recordings/.convert_queue.json');
    // The queues store the path as record.php built it; match on that and on the real path.
    $keys = array_unique([$d, REC_DIR . '/recordings/' . basename($chanDir) . '/' . basename($d)]);
    foreach ($keys as $k) {
        if ($busy = recording_busy($k, $st, $convertQ)) $fail(409, "Can't delete: $busy.");
    }
    $bytes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) $bytes += $f->getSize();
    foreach (['convert', 'upload'] as $qn) {
        dash_queue_update($qn, function (array &$q) use ($keys) { foreach ($keys as $k) unset($q[$k]); });
    }
    rrmdir($d);
    if (is_dir($d)) $fail(500, 'Could not delete everything - some files are left in ' . basename($d));
    @file_put_contents("$chanDir/recorder.log", sprintf("[%s] Recording deleted via the dashboard: %s (%.1f GB)\n",
        date('Y-m-d H:i:s'), basename($d), $bytes / 1073741824), FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true, 'freed' => $bytes]);
    exit;
}

// ---------------------------------------------------------------- manual YouTube upload
// Puts a finished recording in the same upload queue the automatic upload uses; the next cron run
// (when nothing is recording) uploads it. op 'cancel' takes it out again.
if (isset($_GET['upload'])) {
    $in = check_action_request();
    $op = (string)($in['op'] ?? 'queue');
    $d = recording_dir($in['channel'] ?? null, $in['folder'] ?? null);
    if (!$d) action_fail(404, 'That recording no longer exists.');
    $chanDir = dirname($d);
    // Same path form record.php uses for its queue keys; also match the real path.
    $key  = REC_DIR . '/recordings/' . basename($chanDir) . '/' . basename($d);
    $keys = array_unique([$key, $d]);
    $qFile = REC_DIR . '/recordings/.upload_queue.json';
    $logLine = function (string $msg) use ($chanDir) {
        @file_put_contents("$chanDir/recorder.log", '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND | LOCK_EX);
    };

    if ($op === 'cancel') {
        $found = false;
        dash_queue_update('upload', function (array &$q) use ($keys, &$found) {
            foreach ($keys as $k) if (isset($q[$k])) { unset($q[$k]); $found = true; }
        });
        dash_queue_update('convert', function (array &$q) use ($keys, &$found) {
            foreach ($keys as $k) if (!empty($q[$k]['youtube'])) { $q[$k]['youtube'] = null; $found = true; }
        });
        if (!$found) action_fail(409, 'This recording is not waiting for an upload.');
        $logLine('YouTube upload cancelled via the dashboard: ' . basename($d)
            . '');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($op !== 'queue') action_fail(400, 'Unknown action');

    $acct = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['account'] ?? '')));
    if ($acct === '' || !is_file(REC_DIR . "/secret/youtube_$acct.enc")) {
        action_fail(409, $acct === '' ? 'Choose a YouTube account.' : "YouTube account '$acct' is not set up (see docs/youtube.md).");
    }
    $st = read_json("$chanDir/state.json");
    if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) {
        action_fail(409, 'This stream is still being recorded. Pick a YouTube account in the streamer\'s settings '
            . 'and it is uploaded automatically when the stream ends.');
    }
    $parts = glob("$d/parts/*.ts") ?: [];
    if (!is_file("$d/video.mp4") && !is_file("$d/video.ts") && !$parts) action_fail(409, 'This recording has no video.');
    $uploadQ = read_json($qFile);
    foreach ($keys as $k) if (isset($uploadQ[$k])) action_fail(409, 'This recording is already waiting for an upload.');

    // Still in the MP4 conversion queue: upload once that is done (as the automatic upload does).
    $convertQ = read_json(REC_DIR . '/recordings/.convert_queue.json');
    $inConvert = null;
    foreach ($keys as $k) if (isset($convertQ[$k])) $inConvert = $k;
    if ($inConvert !== null) {
        dash_queue_update('convert', function (array &$q) use ($inConvert, $acct) {
            if (isset($q[$inConvert])) $q[$inConvert]['youtube'] = $acct;
        });
        $logLine("YouTube upload to '$acct' requested via the dashboard: " . basename($d) . ' (after the MP4 conversion)');
        echo json_encode(['ok' => true, 'after_convert' => true]);
        exit;
    }
    if (!is_file("$d/video.mp4") && !is_file("$d/video.ts")) {
        action_fail(409, 'The video is still in parts and not queued for conversion. Put an empty convert-again.txt in the folder first.');
    }
    if (!is_file($qFile)) @file_put_contents($qFile, '{}');
    $added = false;
    dash_queue_update('upload', function (array &$q) use ($key, $acct, &$added) {
        if (isset($q[$key])) return;
        $q[$key] = ['account' => $acct, 'tries' => 0, 'session' => null, 'offset' => 0, 'size' => 0, 'file' => null,
            'queued' => time(), 'not_before' => 0, 'error' => null, 'manual' => true];
        $added = true;
    });
    if (!$added) action_fail(409, 'This recording is already waiting for an upload.');
    $logLine("YouTube upload to '$acct' requested via the dashboard: " . basename($d));
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(collect(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
ob_start('with_nonce'); // every <script> of the page below gets this load's nonce
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Stream Recorder™</title>
<link rel="icon" type="image/png" href="<?= FAVICON ?>">
<meta name="dash-token" content="<?= htmlspecialchars($csrf) ?>">
<style>
  :root {
    --bg: #0f1115; --panel: #171a21; --line: #262a33; --text: #e6e8ec; --muted: #8b93a1;
    --live: #ff4d5a; --ok: #3ccf91; --warn: #f2b84b; --bad: #ff6b6b; --accent: #8f7cff;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 24px; border-bottom: 1px solid var(--line);
           position: sticky; top: 0; z-index: 30; background: rgba(15,17,21,.94); backdrop-filter: blur(8px); }
  footer { max-width: 1200px; margin: 0 auto; padding: 0 24px 28px; display: flex; justify-content: flex-end; align-items: center; gap: 8px; }
  .foot-chip { font-size: 13px; color: var(--muted); background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 6px 12px; }
  .foot-chip a { color: var(--text); text-decoration: none; }
  .foot-chip a:hover { text-decoration: underline; }
  .act.totop { font-size: 15px; line-height: 1; padding: 6px 11px; }
  /* AI summary */
  .aibody { padding: 12px 16px 16px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; font-size: 13px; }
  .aibody p { margin: 0; color: var(--muted); }
  .airow { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; }
  .airow select, .airow input { background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 7px; padding: 5px 8px; font: inherit; font-size: 13px; max-width: 100%; }
  .airow input[type=password] { width: 340px; }
  .aistatus { font-size: 13px; }
  .aistatus.ok { color: var(--ok); } .aistatus.warn { color: var(--warn); } .aistatus.bad { color: var(--bad); }
  .aibody details { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; }
  .aibody summary { cursor: pointer; color: var(--text); }
  .aibody ol, .aibody ul { margin: 8px 0 2px; padding-left: 20px; color: var(--muted); }
  .aibody li { margin: 3px 0; }
  .aibody a { color: var(--accent); }
  .act.on { border-color: var(--accent); color: var(--text); }
  tr.sumrow td { background: rgba(143,124,255,.06); border-radius: 8px; padding: 12px 14px; }
  .md { font-size: 14px; line-height: 1.5; max-width: 860px; }
  .sumrow .md h3, .sumrow .md h4 { text-transform: none; letter-spacing: normal; color: var(--text); font-weight: 650; }
  .md h3 { font-size: 16px; margin: 2px 0 8px; } .md h4 { font-size: 14px; margin: 14px 0 4px; }
  .md p { margin: 6px 0; } .md ul { margin: 4px 0; padding-left: 20px; } .md li { margin: 3px 0; }
  .md .ts { font-variant-numeric: tabular-nums; color: var(--accent); margin-right: 4px; text-decoration: none; }
  .md a.ts:hover { text-decoration: underline; }
  .reader { position: fixed; inset: 0; z-index: 100; background: rgba(5,6,9,.72); display: flex; justify-content: center; padding: 24px; }
  .rbox { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; width: min(920px, 100%); display: flex; flex-direction: column; min-height: 0; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  .rhead { display: flex; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--line); }
  .rhead .rtitle { flex: 1; min-width: 0; color: var(--muted); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .rhead a.act { text-decoration: none; }
  .rbody { overflow: auto; padding: 18px 26px 26px; }
  .rbody .md { font-size: 15px; max-width: none; }
  .rbody .md h3 { font-size: 20px; } .rbody .md h4 { font-size: 16px; }
  .rbody .md h3, .rbody .md h4 { text-transform: none; letter-spacing: normal; color: var(--text); font-weight: 650; }
  @media (max-width: 640px) { .reader { padding: 0; } .rbox { border-radius: 0; } .rbody { padding: 14px 16px 22px; } }
  .sumfoot { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; }
  header h1 { font-size: 17px; margin: 0; font-weight: 650; letter-spacing: .2px; }
  .updated { color: var(--muted); font-size: 12px; }
  main { padding: 20px 24px 40px; max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: minmax(0, 1fr); gap: 18px; }
  /* Long text without spaces (links, titles) wraps instead of widening the page on phones. */
  main { overflow-wrap: anywhere; }
  header h1 { overflow-wrap: normal; word-break: keep-all; }
  .logout { display: inline-flex; gap: 6px; }
  /* Phones: the header stays at the top while scrolling, so it is kept to about two short rows. */
  @media (max-width: 640px) {
    header { padding: 8px 12px; gap: 6px 10px; flex-wrap: wrap; }
    header h1 { font-size: 15px; white-space: nowrap; }
    header .hstat { gap: 6px; justify-content: flex-start; flex: 1 1 auto; }
    header .updated { display: none; }
    header .chip { font-size: 11px; padding: 2px 8px; gap: 4px; }
    header .act { font-size: 11px; padding: 2px 7px; }
  }
  .notice { border-radius: 10px; padding: 12px 16px; font-size: 13px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .notice.bad { background: rgba(255,107,107,.1); border: 1px solid rgba(255,107,107,.4); color: var(--text); }
  .notice.info { background: rgba(143,124,255,.08); border: 1px solid rgba(143,124,255,.35); }
  .notice b { color: var(--text); }
  /* One row of tiles that share the width, however many there are */
  .strip { display: flex; flex-wrap: nowrap; gap: 12px; overflow-x: auto; }
  .tile { flex: 1 1 0; min-width: 120px; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
  .tile .label, .tile .value, .tile .sub { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .tile .label { color: var(--muted); font-size: 12px; margin-bottom: 4px; }
  .tile .value { font-size: 15px; font-weight: 600; }
  .tile .sub { color: var(--muted); font-size: 12px; margin-top: 2px; }
  .hstat { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
  .chip { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text); background: var(--panel); border: 1px solid var(--line); border-radius: 99px; padding: 4px 10px; white-space: nowrap; }
  .chip:empty { display: none; }
  .chip .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--muted); }
  .chip.ok .dot { background: var(--ok); } .chip.warn .dot { background: var(--warn); } .chip.bad .dot { background: var(--bad); }
  .chip.bad { border-color: rgba(255,107,107,.5); } .chip.warn { border-color: rgba(242,184,75,.45); }
  .chip .k { color: var(--muted); }
  .card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .card-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .card-head h2 { margin: 0; font-size: 16px; }
  .acts { display: flex; gap: 6px; justify-content: flex-end; margin-top: 6px; }
  .loghead { display: flex; align-items: baseline; gap: 10px; }
  .card.moments { margin-top: 18px; }
  .mbody { padding: 12px 16px 16px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
  .mhelp { margin: 0; color: var(--muted); font-size: 13px; }
  .mrules { display: grid; gap: 6px; }
  .mrule { display: flex; align-items: center; gap: 10px; font-size: 13px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 8px; }
  .mrule.off { opacity: .55; }
  .mrule .mtxt { flex: 1; min-width: 0; }
  .marrow { color: var(--muted); }
  .mform, .mtest { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 13px; color: var(--muted); }
  .mform select, .mform input, .mtest select { background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 7px; padding: 5px 8px; font: inherit; font-size: 13px; }
  .mform input[type=number] { width: 70px; }
  .mform input:not([type]) { width: 190px; max-width: 100%; }
  .mform select { max-width: 100%; }
  .mtest select { max-width: min(520px, 100%); }
  .mout { display: grid; gap: 6px; font-size: 13px; }
  .mout .k { color: var(--muted); }
  .mdesc { margin: 0; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; font-size: 12px; white-space: pre-wrap; max-height: 240px; overflow: auto; }
  .loglinks { margin-left: auto; display: flex; gap: 10px; font-size: 11px; font-weight: 500; text-transform: none; letter-spacing: 0; }
  .loglinks a { color: var(--accent); text-decoration: none; }
  .loglinks a:hover { text-decoration: underline; }
  .loglinks .muted { color: var(--muted); }
  :root { --s1: #3987e5; --s2: #d95926; --s3: #199e70; }
  .card.io { margin-bottom: 18px; }
  .io-ctrl { margin-left: auto; display: flex; gap: 6px; }
  .act.sel { border-color: var(--accent); color: var(--text); background: rgba(143,124,255,.14); }
  .io-legend { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; padding: 10px 16px 0; font-size: 12px; color: var(--muted); }
  .io-legend .lg { display: flex; align-items: center; gap: 6px; }
  .io-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
  .io-legend i.dash { width: 14px; height: 0; border-top: 2px dashed var(--warn); border-radius: 0; }
  .io-now { margin-left: auto; color: var(--text); font-variant-numeric: tabular-nums; }
  .io-plot { position: relative; padding: 6px 8px 8px; }
  .io-plot svg { display: block; width: 100%; height: auto; }
  .io-plot .grid { stroke: var(--line); stroke-width: 1; }
  .io-plot .axis { stroke: #3a3f4b; stroke-width: 1; }
  .io-plot .tick { fill: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
  .io-plot .area { opacity: .9; }
  .io-plot .edge { fill: none; stroke: var(--panel); stroke-width: 2; stroke-linejoin: round; }
  .io-plot .limit { stroke: var(--warn); stroke-width: 1.5; stroke-dasharray: 5 4; }
  .io-plot .cross { stroke: var(--muted); stroke-width: 1; }
  .io-tip { position: absolute; top: 8px; background: #20242d; border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; font-size: 12px; pointer-events: none; min-width: 170px; box-shadow: 0 4px 14px rgba(0,0,0,.35); }
  .io-tip .tt { color: var(--muted); margin-bottom: 4px; }
  .io-tip .row { display: flex; align-items: center; gap: 6px; }
  .io-tip .row i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }
  .io-tip .row b { margin-left: auto; font-variant-numeric: tabular-nums; font-weight: 600; }
  .io-tip .total { border-top: 1px solid var(--line); margin-top: 4px; padding-top: 4px; }
  .io-tip .muted { color: var(--muted); }
  .io-table { max-height: 260px; overflow: auto; padding: 8px 16px 12px; }
  .io-table table { width: 100%; font-size: 12px; font-variant-numeric: tabular-nums; }
  .io-table th { text-align: right; color: var(--muted); font-weight: 600; position: sticky; top: 0; background: var(--panel); }
  .io-table th:first-child { text-align: left; }
  .empty.pad { padding: 16px; }
  .streamers-head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 26px 0 12px; }
  .streamers-head h2 { margin: 0; font-size: 16px; }
  #addForm { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  #addForm input[type=text], #addName { background: var(--panel); border: 1px solid var(--line); color: var(--text); border-radius: 8px; padding: 6px 10px; font: inherit; font-size: 13px; width: 220px; }
  #addName:focus { outline: none; border-color: var(--accent); }
  .chk { color: var(--muted); font-size: 13px; display: flex; gap: 6px; align-items: center; }
  .act.add { color: var(--text); border-color: var(--accent); }
  .switch { width: 36px; height: 20px; border-radius: 10px; border: 1px solid var(--line); background: #262a33; position: relative; cursor: pointer; padding: 0; flex: none; }
  .switch i { position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: var(--muted); transition: left .15s; }
  .switch.on { background: rgba(60,207,145,.25); border-color: rgba(60,207,145,.6); }
  .switch.on i { left: 18px; background: var(--ok); }
  .switch:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .b-idle { color: var(--ok); background: rgba(60,207,145,.12); }
  .settings { display: flex; gap: 18px; flex-wrap: wrap; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--line); font-size: 13px; color: var(--muted); }
  .settings select { background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 3px 6px; font: inherit; font-size: 13px; margin-left: 4px; }
  .settings > .act, .settings > .rmconfirm { margin-left: auto; }
  .rmconfirm .act { margin-left: 6px; }
  .stopconfirm { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 16px; background: rgba(255,107,107,.08); border-bottom: 1px solid var(--line); font-size: 13px; }
  .del-err.pad { padding: 8px 16px; margin: 0; }
  .card.folded > .stopconfirm { display: flex; }
  .act { font: inherit; font-size: 12px; padding: 3px 9px; border-radius: 6px; border: 1px solid var(--line);
         background: transparent; color: var(--text); cursor: pointer; text-decoration: none; white-space: nowrap; }
  .act:hover:not(:disabled) { border-color: var(--muted); }
  .act:disabled { opacity: .35; cursor: not-allowed; }
  .act.play { color: var(--ok); border-color: rgba(60,207,145,.4); }
  .act.del { color: var(--muted); }
  .act.del:hover:not(:disabled) { color: var(--bad); border-color: var(--bad); }
  .act.del.sure { color: #fff; background: var(--bad); border-color: var(--bad); }
  tr.confirm td { background: rgba(255,107,107,.08); border-radius: 8px; padding: 10px 12px; font-size: 13px; }
  tr.confirm .act { margin-left: 6px; }
  .del-err { color: var(--bad); margin-top: 6px; }
  #channels .card-head { cursor: pointer; user-select: none; }
  #channels .card-head:hover { background: rgba(255,255,255,.03); }
  .chev { color: var(--muted); font-size: 14px; width: 12px; display: inline-block; transition: transform .15s; }
  .fold-sum { display: none; color: var(--muted); font-size: 13px; margin-left: auto; }
  .card.folded .chev { transform: rotate(-90deg); }
  .card.folded .fold-sum { display: inline; }
  .card.folded .card-head { border-bottom: 0; }
  .card.folded > :not(.card-head) { display: none; }
  .badge { font-size: 11px; font-weight: 700; letter-spacing: .5px; padding: 3px 8px; border-radius: 99px; text-transform: uppercase; }
  .b-live { background: rgba(255,77,90,.15); color: var(--live); }
  .b-live::before { content: ""; display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--live); margin-right: 6px; animation: pulse 1.4s infinite; vertical-align: 1px; }
  .b-off { background: #222630; color: var(--muted); }
  .b-warn { background: rgba(242,184,75,.15); color: var(--warn); }
  @keyframes pulse { 50% { opacity: .3; } }
  .now { padding: 14px 16px; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px 18px; border-bottom: 1px solid var(--line); }
  .now .title { grid-column: 1 / -1; font-size: 15px; }
  .now .title span { color: var(--muted); }
  .stat .k { color: var(--muted); font-size: 12px; }
  .stat .v { font-weight: 600; font-variant-numeric: tabular-nums; }
  .cols { display: grid; grid-template-columns: 1.1fr 1fr; }
  @media (max-width: 860px) { .cols { grid-template-columns: 1fr; } }
  .section { padding: 12px 16px; min-width: 0; }
  .section + .section { border-left: 1px solid var(--line); }
  @media (max-width: 860px) { .section + .section { border-left: 0; border-top: 1px solid var(--line); } }
  .section h3 { margin: 0 0 8px; font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .6px; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 7px 0; border-top: 1px solid var(--line); vertical-align: top; }
  tr:first-child td { border-top: 0; }
  td.r { text-align: right; white-space: nowrap; padding-left: 10px; }
  /* Phones: a recording's status and buttons go under its title instead of squeezing it. */
  @media (max-width: 640px) {
    .rectable tr:not(.confirm) td { display: block; }
    .rectable tr:not(.confirm) td.r { text-align: left; white-space: normal; padding: 0 0 9px; border-top: 0; }
    .rectable td.r > .pill, .rectable td.r > .rec-sub { display: inline-block; margin-right: 8px; }
    .rectable .acts { justify-content: flex-start; flex-wrap: wrap; }
  }
  .rec-title { font-weight: 550; }
  .rec-sub { color: var(--muted); font-size: 12px; }
  .pill { font-size: 11px; padding: 2px 7px; border-radius: 6px; background: #222630; color: var(--muted); white-space: nowrap; }
  .p-recording { color: var(--live); background: rgba(255,77,90,.12); }
  .p-converting, .p-uploading { color: var(--accent); background: rgba(143,124,255,.14); }
  .p-upload-waiting { color: var(--warn); background: rgba(242,184,75,.13); }
  .card.scanask { border-color: rgba(143,124,255,.45); }
  .card.scanask .card-head { gap: 10px; }
  .askrow { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 16px; padding: 12px 16px; border-top: 1px solid var(--line); }
  .card-head + .askrow { border-top: 0; }
  .askrow .who { color: var(--accent); font-weight: 600; font-size: 12px; text-transform: none; }
  .askrow .subj { font-weight: 600; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .askrow .meta { color: var(--muted); font-size: 12px; margin-top: 3px; }
  .askrow .meta .wait { color: var(--warn); }
  .askrow .acts { display: flex; gap: 8px; }
  .askrow .act { padding: 6px 14px; font-size: 13px; }
  .askrow .act.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
  .askrow .act.primary:hover { filter: brightness(1.1); }
  @media (max-width: 640px) { .askrow { grid-template-columns: 1fr; } }
  .p-upload-queued { color: var(--accent); background: rgba(143,124,255,.08); }
  tr.confirm.yt td { background: rgba(143,124,255,.07); }
  tr.confirm select { margin: 0 6px; }
  .bar { height: 4px; background: #262a33; border-radius: 4px; margin-top: 5px; overflow: hidden; }
  .bar > i { display: block; height: 100%; background: var(--accent); }
  a { color: #b3a8ff; }
  .log { font: 12px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace; color: #c3c8d1; max-height: 340px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
  .log div { padding: 1px 0; }
  .log .t { color: var(--muted); }
  .log .warn { color: var(--warn); }
  .log .bad { color: var(--bad); }
  .log .good { color: var(--ok); }
  .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); } .live { color: var(--live); }
  .empty { color: var(--muted); padding: 30px; text-align: center; }
  .alerts .card-head { justify-content: space-between; }
  .alert { display: grid; grid-template-columns: 10px 1fr auto; gap: 10px; padding: 10px 16px; border-top: 1px solid var(--line); }
  .alert:first-child { border-top: 0; }
  .alert .dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; background: var(--muted); }
  .alert.warning .dot { background: var(--warn); } .alert.success .dot { background: var(--ok); } .alert.info .dot { background: #6cb6ff; }
  .alert.new { background: rgba(143,124,255,.07); }
  .alert .subj { font-weight: 600; }
  .alert .meta { color: var(--muted); font-size: 12px; }
  .alert details { margin-top: 4px; } .alert summary { cursor: pointer; color: var(--muted); font-size: 12px; }
  .alert pre { white-space: pre-wrap; word-break: break-word; font: 12px/1.5 ui-monospace, Consolas, monospace; color: #c3c8d1; margin: 6px 0 0; }
  .alert .when { color: var(--muted); font-size: 12px; white-space: nowrap; }
  .badge-new { background: rgba(143,124,255,.18); color: #b3a8ff; }
  .linkbtn { background: none; border: 1px solid var(--line); color: var(--muted); border-radius: 8px; padding: 4px 10px; font: inherit; font-size: 12px; cursor: pointer; }
  .linkbtn:hover { color: var(--text); }
  #channels { display: grid; gap: 18px; }
  .bar.convert > i { background: #3ccf91; }
  .p-convert-queued { color: var(--muted); }
  .err { background: rgba(255,107,107,.1); color: var(--bad); border: 1px solid rgba(255,107,107,.3); padding: 10px 14px; border-radius: 10px; display: none; }
</style>
</head>
<body>
<header>
  <h1>Stream Recorder™</h1>
  <div class="hstat">
    <span class="chip" id="hToken"></span>
    <span class="chip" id="hCron"></span>
    <span class="updated" id="updated">Loading…</span>
    <span class="logout"><button class="act" id="logoutBtn" title="Log out in this browser">Log out</button><button class="act" id="logoutAllBtn" title="End every login, on all your devices and browsers">Log out everywhere</button></span>
  </div>
</header>
<main>
  <div class="err" id="err"></div>
  <div id="notices"></div>
  <div class="strip" id="strip"></div>
  <div id="io"></div>
  <div id="scanAsks"></div>
  <div id="alerts"></div>
  <div class="streamers-head">
    <h2>Streamers</h2>
    <form id="addForm" autocomplete="off">
      <input id="addName" placeholder="Twitch name (twitch.tv/…)" maxlength="40" spellcheck="false">
      <label class="chk"><input type="checkbox" id="addOn" checked> record right away</label>
      <button class="act add" type="submit">Add streamer</button>
      <span id="addErr" class="del-err"></span>
    </form>
  </div>
  <div id="channels"></div>
  <div id="momentsBox"></div>
  <div id="aiBox"></div>
</main>
<footer>
  <span class="foot-chip">&copy; <?= date('Y') ?> · <a href="https://github.com/LandonGam3r/stream-recorder" target="_blank" rel="noopener noreferrer">Stream Recorder™</a> · By Landon</span>
  <button class="act totop" id="toTop" type="button" title="Back to the top" aria-label="Back to the top">&uarr;</button>
</footer>
<script>
(function () {
  const $ = (s) => document.querySelector(s);
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const mb = (b) => b == null ? '–' : b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(0) + ' MB';
  const dur = (s) => { s = Math.round(s || 0); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm ' + String(s % 60).padStart(2, '0') + 's'; };
  const ago = (t, now) => { if (!t) return 'never'; const d = now - t; return d < 60 ? d + 's ago' : d < 3600 ? Math.floor(d / 60) + ' min ago' : d < 86400 ? Math.floor(d / 3600) + ' h ago' : Math.floor(d / 86400) + ' d ago'; };

  function tile(label, value, sub, cls) {
    const t = el('div', 'tile');
    t.append(el('div', 'label', label));
    const v = el('div', 'value' + (cls ? ' ' + cls : ''), value);
    t.append(v);
    if (sub) t.append(el('div', 'sub', sub));
    t.title = label + ': ' + value + (sub ? '\n' + sub : ''); // full text when it's cut off
    return t;
  }

  function stat(k, v) { const s = el('div', 'stat'); s.append(el('div', 'k', k), el('div', 'v', v)); return s; }

  function logLine(line) {
    const d = el('div');
    const m = line.match(/^(\[[^\]]+\])\s?(.*)$/);
    const msg = m ? m[2] : line;
    if (m) d.append(el('span', 't', m[1] + ' '));
    let cls = '';
    if (/^ENDED/.test(msg)) cls = 'good';
    else if (/FAILED|PROBLEM|killed by the host|GAP:|[Ee]rror|failed/.test(msg)) cls = 'bad';
    else if (/WARNING|paused|Run skipped|rejected|not available|missing/.test(msg)) cls = 'warn';
    else if (/DONE|MP4 ready|LIVE|ENDED|works again|Token OK|stored encrypted/.test(msg)) cls = 'good';
    d.append(el('span', cls, msg));
    return d;
  }

  // "Read" state lives in this browser only (the dashboard never writes to the server).
  const SEEN_KEY = 'recdash-alerts-seen';
  const getSeen = () => { try { return parseFloat(localStorage.getItem(SEEN_KEY) || '0') || 0; } catch (e) { return 0; } };
  const setSeen = (v) => { try { localStorage.setItem(SEEN_KEY, String(v)); } catch (e) {} };
  let showAll = false;

  function renderAlerts(alerts, now) {
    const box = $('#alerts'); box.replaceChildren();
    if (!alerts || !alerts.length) return 0;
    const seen = getSeen();
    const fresh = alerts.filter(a => parseFloat(a.id) > seen).length;
    const card = el('div', 'card alerts');
    const head = el('div', 'card-head');
    const left = el('div'); left.style.display = 'flex'; left.style.gap = '10px'; left.style.alignItems = 'center';
    left.append(el('h2', null, 'Alerts'));
    if (fresh) left.append(el('span', 'badge badge-new', fresh + ' new'));
    const right = el('div'); right.style.display = 'flex'; right.style.gap = '6px';
    if (fresh) { const b = el('button', 'linkbtn', 'Mark all as read'); b.onclick = () => { setSeen(parseFloat(alerts[0].id)); load(); }; right.append(b); }
    if (alerts.length > 5) { const b = el('button', 'linkbtn', showAll ? 'Show fewer' : 'Show all ' + alerts.length); b.onclick = () => { showAll = !showAll; load(); }; right.append(b); }
    head.append(left, right); card.append(head);
    (showAll ? alerts : alerts.slice(0, Math.max(5, fresh))).forEach(a => {
      const row = el('div', 'alert ' + a.level + (parseFloat(a.id) > seen ? ' new' : ''));
      row.append(el('div', 'dot'));
      const mid = el('div');
      mid.append(el('div', 'subj', a.subject.replace(/^\[Recorder\]\s*/, '')));
      const meta = el('div', 'meta', a.channel || '');
      mid.append(meta);
      const d = el('details'); d.append(el('summary', null, 'Details'), el('pre', null, a.body)); mid.append(d);
      row.append(mid);
      const t = Math.floor(new Date(a.time.replace(' ', 'T')).getTime() / 1000);
      row.append(el('div', 'when', isNaN(t) ? a.time : a.time.slice(5, 16).replace('-', '/')));
      card.append(row);
    });
    box.append(card);
    return fresh;
  }

  function foldState() { try { return JSON.parse(localStorage.getItem('rec-folded') || '{}') || {}; } catch (e) { return {}; } }
  function saveFoldState(st) { try { localStorage.setItem('rec-folded', JSON.stringify(st)); } catch (e) {} }

  // ---- IO graph
  const IO_SERIES = [ // fixed order = stacking order (bottom first) = legend order
    { key: 1, name: 'Recording', color: 'var(--s1)' },
    { key: 0, name: 'Converting', color: 'var(--s2)' },
    { key: 2, name: 'Other (website, tools)', color: 'var(--s3)' },
  ];
  const IO_RANGES = { '1h': [3600, 60], '6h': [21600, 180], '24h': [86400, 600] }; // span, bin seconds
  let ioData = null, ioTable = false;
  let ioRange = (() => { try { return localStorage.getItem('rec-io-range') || '6h'; } catch (e) { return '6h'; } })();
  if (!IO_RANGES[ioRange]) ioRange = '6h';
  const svgEl = (tag, attrs) => { const e = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; };
  const mbs = v => v >= 10 ? v.toFixed(0) : v >= 1 ? v.toFixed(1) : v.toFixed(2);
  const hm = t => new Date(t * 1000).toTimeString().slice(0, 5);

  async function loadIo() {
    try {
      const r = await fetch('?io=1', { credentials: 'same-origin', cache: 'no-store' });
      if (r.ok) { ioData = await r.json(); renderIo(); }
    } catch (e) {}
  }

  function ioBins() {
    const [span, bin] = IO_RANGES[ioRange];
    const end = Math.floor(ioData.now / 60) * 60;          // current (partial) minute excluded
    const start = Math.floor((end - span) / bin) * bin;
    const n = Math.ceil((end - start) / bin);
    const bins = Array.from({ length: n }, (_, i) => ({ t: start + i * bin, v: [0, 0, 0], have: 0 }));
    ioData.rows.forEach(([t, conv, rec, other]) => {
      if (t < start || t >= end) return;
      const b = bins[Math.floor((t - start) / bin)];
      b.v[0] += conv; b.v[1] += rec; b.v[2] += other; b.have++;
    });
    bins.forEach(b => { b.v = b.v.map(x => x / bin / 1048576); b.total = b.v[0] + b.v[1] + b.v[2]; }); // average MB/s
    return { bins, bin };
  }

  function renderIo() {
    const box = $('#io'); box.replaceChildren();
    if (!ioData) return;
    const card = el('div', 'card io');
    const head = el('div', 'card-head');
    head.append(el('h2', null, 'Disk IO'));
    head.append(el('span', 'updated', 'this account’s processes, MB/s averaged per ' + (IO_RANGES[ioRange][1] / 60) + ' min'));
    const right = el('div', 'io-ctrl');
    Object.keys(IO_RANGES).forEach(k => {
      const b = el('button', 'act' + (k === ioRange ? ' sel' : ''), k);
      b.onclick = () => { ioRange = k; try { localStorage.setItem('rec-io-range', k); } catch (e) {} renderIo(); };
      right.append(b);
    });
    const tb = el('button', 'act', ioTable ? 'Graph' : 'Table'); tb.onclick = () => { ioTable = !ioTable; renderIo(); }; right.append(tb);
    head.append(right); card.append(head);
    box.append(card);

    if (!ioData.rows.length) {
      card.append(el('div', 'empty pad', 'No IO data yet. It is measured by the new record.php (started from the single cron line) and appears within a minute or two.'));
      return;
    }
    const { bins } = ioBins();
    const legend = el('div', 'io-legend');
    IO_SERIES.forEach(sr => { const it = el('span', 'lg'); const sw = el('i'); sw.style.background = sr.color; it.append(sw, document.createTextNode(sr.name)); legend.append(it); });
    const lim = ioData.limit_mb;
    if (lim > 0) { const it = el('span', 'lg'); it.append(el('i', 'dash'), document.createTextNode('IO limit ' + lim + ' MB/s')); legend.append(it); }
    const last = bins.slice().reverse().find(b => b.have);
    const peak = bins.reduce((m, b) => Math.max(m, b.total), 0);
    legend.append(el('span', 'io-now', (last ? 'latest ' + mbs(last.total) + ' MB/s · ' : '') + 'peak ' + mbs(peak) + ' MB/s'));
    card.append(legend);

    if (ioTable) {
      const wrap = el('div', 'io-table'); const t = el('table');
      const hr = el('tr'); ['Time'].concat(IO_SERIES.map(x => x.name), ['Total']).forEach(h => hr.append(el('th', null, h))); t.append(hr);
      bins.slice().reverse().filter(b => b.have).forEach(b => {
        const tr = el('tr'); tr.append(el('td', null, hm(b.t)));
        IO_SERIES.forEach(sr => tr.append(el('td', 'r', mbs(b.v[sr.key]))));
        tr.append(el('td', 'r', mbs(b.total))); t.append(tr);
      });
      wrap.append(t); card.append(wrap);
      return;
    }

    const plot = el('div', 'io-plot'); card.append(plot);
    const W = Math.max(320, plot.clientWidth || 900), H = 190, L = 44, R = 12, T = 10, B = 24;
    const pw = W - L - R, ph = H - T - B;
    let ymax = Math.max(peak, lim > 0 ? lim : 0) * 1.12 || 1;
    const step = [0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50, 100].find(s => ymax / s <= 5) || 200;
    ymax = Math.ceil(ymax / step) * step;
    const n = bins.length;
    const x = i => L + (n <= 1 ? 0 : i * pw / (n - 1));
    const y = v => T + ph - v / ymax * ph;
    const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img', 'aria-label': 'Disk IO in MB/s over the last ' + ioRange });
    for (let v = 0; v <= ymax + 1e-9; v += step) {
      svg.append(svgEl('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), class: v === 0 ? 'axis' : 'grid' }));
      const tx = svgEl('text', { x: L - 6, y: y(v) + 4, class: 'tick', 'text-anchor': 'end' }); tx.textContent = v === 0 ? '0' : v.toFixed(step < 1 ? 1 : 0); svg.append(tx);
    }
    const nT = 5;
    for (let k = 0; k <= nT; k++) {
      const i = Math.round(k * (n - 1) / nT);
      const tx = svgEl('text', { x: x(i), y: H - 6, class: 'tick', 'text-anchor': k === 0 ? 'start' : k === nT ? 'end' : 'middle' });
      tx.textContent = hm(bins[i].t); svg.append(tx);
    }
    // stacked areas, bottom first, with a surface-coloured edge between layers
    const base = new Array(n).fill(0);
    IO_SERIES.forEach(sr => {
      const top = bins.map((b, i) => base[i] + b.v[sr.key]);
      let d = 'M' + x(0) + ',' + y(top[0]);
      for (let i = 1; i < n; i++) d += 'L' + x(i) + ',' + y(top[i]);
      for (let i = n - 1; i >= 0; i--) d += 'L' + x(i) + ',' + y(base[i]);
      svg.append(svgEl('path', { d: d + 'Z', fill: sr.color, class: 'area' }));
      let e = 'M' + x(0) + ',' + y(top[0]); for (let i = 1; i < n; i++) e += 'L' + x(i) + ',' + y(top[i]);
      svg.append(svgEl('path', { d: e, class: 'edge' }));
      top.forEach((v, i) => base[i] = v);
    });
    if (lim > 0) {
      svg.append(svgEl('line', { x1: L, x2: W - R, y1: y(lim), y2: y(lim), class: 'limit' }));
      const lt = svgEl('text', { x: W - R - 4, y: y(lim) - 5, class: 'tick', 'text-anchor': 'end' }); lt.textContent = 'limit ' + lim; svg.append(lt);
    }
    // hover: crosshair + tooltip
    const cross = svgEl('line', { y1: T, y2: T + ph, class: 'cross', visibility: 'hidden' }); svg.append(cross);
    const hit = svgEl('rect', { x: L, y: T, width: pw, height: ph, fill: 'transparent' }); svg.append(hit);
    const tip = el('div', 'io-tip'); tip.style.display = 'none';
    plot.append(svg, tip);
    const move = ev => {
      const r = svg.getBoundingClientRect();
      const px = (ev.clientX - r.left) * W / r.width;
      const i = Math.max(0, Math.min(n - 1, Math.round((px - L) / pw * (n - 1))));
      const b = bins[i];
      cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('visibility', 'visible');
      tip.replaceChildren(el('div', 'tt', hm(b.t) + (IO_RANGES[ioRange][1] > 60 ? '–' + hm(b.t + IO_RANGES[ioRange][1]) : '')));
      if (!b.have) tip.append(el('div', 'muted', 'no measurement'));
      else {
        IO_SERIES.slice().reverse().forEach(sr => { const row = el('div', 'row'); const sw = el('i'); sw.style.background = sr.color; row.append(sw, el('span', null, sr.name), el('b', null, mbs(b.v[sr.key]))); tip.append(row); });
        const tr = el('div', 'row total'); tr.append(el('i'), el('span', null, 'Total'), el('b', null, mbs(b.total) + ' MB/s')); tip.append(tr);
      }
      tip.style.display = 'block';
      const left = (x(i) / W) * r.width;
      tip.style.left = Math.min(r.width - tip.offsetWidth - 4, Math.max(4, left + 12)) + 'px';
    };
    hit.addEventListener('mousemove', move);
    hit.addEventListener('mouseleave', () => { tip.style.display = 'none'; cross.setAttribute('visibility', 'hidden'); });
  }
  window.addEventListener('resize', () => { clearTimeout(window.__ioR); window.__ioR = setTimeout(renderIo, 150); });

  // ---- YouTube moments
  const MT = { text: 'Chat message contains', user: 'Message from user', burst: 'Chat burst', category: 'Category or title contains', speech: 'Streamer says (PC scan)' };
  let mUI = null;
  function buildMoments() {
    const card = el('div', 'card moments');
    const head = el('div', 'card-head'); head.append(el('h2', null, 'YouTube moments'));
    head.append(el('span', 'updated', 'scanned after each stream and right before a YouTube upload'));
    card.append(head);
    const body = el('div', 'mbody');
    body.append(el('p', 'mhelp', 'When a rule finds its moment in a recording, its label is added to the YouTube title (if ticked) and the description gets a "Moments" list with clickable timestamps.'));
    const list = el('div', 'mrules'); body.append(list);
    // add form
    const f = el('form', 'mform'); f.autocomplete = 'off';
    const ch = el('select'); ch.title = 'Which streamer';
    const ty = el('select'); Object.entries(MT).forEach(([v, t]) => { const o = el('option', null, t); o.value = v; ty.append(o); });
    const val = el('input'); val.placeholder = 'e.g. raid'; val.maxLength = 100;
    const cnt = el('input'); cnt.type = 'number'; cnt.min = 2; cnt.max = 1000; cnt.value = 15; cnt.title = 'messages';
    const win = el('input'); win.type = 'number'; win.min = 5; win.max = 3600; win.value = 30; win.title = 'seconds';
    const burst = el('span', 'mburst'); burst.append('at least ', cnt, ' messages within ', win, ' s');
    const lab = el('input'); lab.placeholder = 'Label, e.g. RAID!'; lab.maxLength = 40;
    const inTitle = el('input'); inTitle.type = 'checkbox'; inTitle.checked = true;
    const tl = el('label', 'chk'); tl.append(inTitle, ' in title');
    const add = el('button', 'act add', 'Add rule'); add.type = 'submit';
    const err = el('span', 'del-err');
    const upd = () => {
      burst.style.display = ty.value === 'burst' ? '' : 'none';
      val.placeholder = { text: 'word or phrase, e.g. raid', user: 'username', burst: 'word (optional), e.g. LUL', category: 'e.g. Just Chatting', speech: 'word or phrase, e.g. let\'s go' }[ty.value];
    };
    ty.onchange = upd; upd();
    f.append(ch, ty, val, burst, el('span', 'marrow', '→'), lab, tl, add, err);
    f.onsubmit = async e => {
      e.preventDefault(); err.textContent = '';
      try {
        await post('?moments=1', { op: 'add', channel: ch.value, type: ty.value, value: val.value, count: +cnt.value, window: +win.value, label: lab.value, title: inTitle.checked });
        val.value = ''; lab.value = ''; await load();
      } catch (x) { err.textContent = x.message; }
    };
    body.append(f);
    // test
    const tr = el('div', 'mtest');
    const rec = el('select'); rec.title = 'Recording to test the rules on';
    const tb = el('button', 'act', 'Test on this recording'); tb.type = 'button';
    const out = el('div', 'mout');
    tb.onclick = async () => {
      out.replaceChildren(el('div', 'rec-sub', 'Scanning…'));
      const [c, folder] = rec.value.split('/');
      try {
        const j = await post('?moments=1', { op: 'test', channel: c, folder });
        out.replaceChildren();
        out.append(el('div', 'rec-sub', j.count ? j.count + ' moment' + (j.count === 1 ? '' : 's') + ' found' : 'No moments found with the current (switched-on) rules.'));
        const t = el('div', 'mprev'); t.append(el('span', 'k', 'YouTube title: '), el('b', null, j.title)); out.append(t);
        if (j.description) { const pre = el('pre', 'mdesc'); pre.textContent = j.description.trim(); out.append(el('div', 'k', 'Added to the description:'), pre); }
      } catch (x) { out.replaceChildren(el('div', 'del-err', x.message)); }
    };
    tr.append(rec, tb); body.append(tr, out);
    card.append(body);
    $('#momentsBox').append(card);
    mUI = { list, ch, rec, card };
  }
  function describeRule(r) {
    const who = r.channel ? r.channel : 'all streamers';
    const what = r.type === 'burst'
      ? 'at least ' + r.count + ' messages' + (r.value ? ' with "' + r.value + '"' : '') + ' within ' + r.window + ' s'
      : MT[r.type].toLowerCase() + ' "' + r.value + '"';
    return [who + ': ' + what, r.label + (r.title ? ' (title + description)' : ' (description)')];
  }
  function renderMoments(data) {
    if (!data.moments) return;
    if (!mUI) buildMoments();
    if (!data.moments.available) { mUI.card.style.display = 'none'; return; }
    mUI.card.style.display = '';
    const a = document.activeElement;
    // streamer + recording choices (kept as chosen)
    if (!(a && a.closest && a.closest('#momentsBox'))) {
      const keep1 = mUI.ch.value, keep2 = mUI.rec.value;
      mUI.ch.replaceChildren(Object.assign(el('option', null, 'All streamers'), { value: '' }), ...data.channels.map(c => Object.assign(el('option', null, c.name), { value: c.name })));
      mUI.ch.value = keep1;
      const recs = [];
      data.channels.forEach(c => c.recordings.forEach(r => { if (r.files['chat.txt'] || r.files['video.mp4']) recs.push([c.name + '/' + r.folder, c.name + ' · ' + (r.started ? r.started.slice(0, 10) : '') + ' · ' + (r.title || r.folder)]); }));
      mUI.rec.replaceChildren(...recs.map(([v, t]) => Object.assign(el('option', null, t.length > 70 ? t.slice(0, 69) + '…' : t), { value: v })));
      if (keep2) mUI.rec.value = keep2;
    }
    const sig = JSON.stringify(data.moments.rules);
    if (mUI.sig === sig) return;
    mUI.sig = sig;
    mUI.list.replaceChildren();
    if (!data.moments.rules.length) mUI.list.append(el('div', 'rec-sub', 'No rules yet - add one below.'));
    data.moments.rules.forEach(r => {
      const row = el('div', 'mrule' + (r.enabled ? '' : ' off'));
      const sw = el('button', 'switch' + (r.enabled ? ' on' : '')); sw.append(el('i')); sw.title = r.enabled ? 'On - click to switch off' : 'Off - click to switch on';
      sw.onclick = async () => { try { await post('?moments=1', { op: 'toggle', id: r.id, enabled: !r.enabled }); await load(); } catch (x) {} };
      const [w, l] = describeRule(r);
      const txt = el('div', 'mtxt'); txt.append(el('span', null, w), el('span', 'marrow', ' → '), el('b', null, l));
      const del = el('button', 'act del', 'Delete');
      del.onclick = async () => { try { await post('?moments=1', { op: 'delete', id: r.id }); await load(); } catch (x) {} };
      row.append(sw, txt, del); mUI.list.append(row);
    });
  }

  let lastData = null, pendingDelete = null, deleteError = null, deleting = false;
  let pendingYt = null, ytError = null, ytBusy = false, ytCancelErr = null;
  const token = document.querySelector('meta[name="dash-token"]').content;

  async function post(url, body) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-Dashboard-Token': token }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
    if (!j.ok) throw new Error(j.error || 'HTTP ' + r.status);
    return j;
  }

  // ---- streamers: add, switch on/off, settings, remove
  let pendingStop = null, pendingRemove = null, streamerError = {};
  async function streamerAction(channel, body) {
    delete streamerError[channel];
    try { await post('?streamers=1', Object.assign({ channel }, body)); }
    catch (e) { streamerError[channel] = e.message; }
    pendingStop = pendingRemove = null;
    await load();
  }
  $('#addForm').addEventListener('submit', async e => {
    e.preventDefault();
    const name = $('#addName').value.trim().replace(/^.*twitch\.tv\//i, '').replace(/[/?#].*$/, '').toLowerCase();
    $('#addErr').textContent = '';
    if (!name) return;
    try {
      await post('?streamers=1', { op: 'add', channel: name, enabled: $('#addOn').checked });
      $('#addName').value = '';
      await load();
    } catch (err) { $('#addErr').textContent = err.message; }
  });

  // ---- PC scan
  const SCAN_ACTIVE = ['requested', 'preparing', 'ready', 'scanning'];
  const SCAN_TEXT = { ask: 'waiting for your choice', requested: 'starting - the server prepares the sound at the next cron run',
    preparing: 'the server is preparing the sound', ready: 'sound ready - continue on the scan page', scanning: 'scanning on your PC',
    done: 'done', skipped: 'skipped', cancelled: 'cancelled', failed: 'failed' };
  let scanErr = {};
  function langName(code) {
    if (!code) return '';
    try { return new Intl.DisplayNames(['en'], { type: 'language' }).of(code) || code; } catch (e) { return code; }
  }
  function scanLine(c, r) {
    const sc = r.scan, d = el('div', 'rec-sub');
    let t = 'PC scan: ' + (SCAN_TEXT[sc.state] || sc.state);
    if (sc.state === 'scanning' && sc.pieces) t += ' (' + sc.done + ' of ' + sc.pieces + ' pieces)';
    if (sc.state === 'preparing' && sc.prep && sc.prep.total) t += ' (' + Math.min(100, Math.floor(100 * sc.prep.done / sc.prep.total)) + '%' + (sc.prep.speed ? ', max ' + sc.prep.speed + '× real time' : '') + ')';
    if (sc.note && SCAN_ACTIVE.includes(sc.state)) t += ' · ' + sc.note;
    if (sc.state === 'failed' && sc.error) t += ' · ' + sc.error;
    d.textContent = t;
    const subs = sc.outputs.filter(o => o.kind === 'subs').map(o => o.original ? 'spoken' + (o.lang ? ' (' + langName(o.lang) + ')' : '') : langName(o.lang));
    const chat = sc.outputs.filter(o => o.kind === 'chat').map(o => langName(o.lang));
    if (subs.length) d.append(' · subtitles: ' + subs.join(', '));
    if (chat.length) d.append(' · chat in ' + chat.join(', '));
    const txt = sc.outputs.find(o => o.kind === 'text');
    if (txt) {
      const l = el('a', null, 'text version'); l.href = '?ch=' + encodeURIComponent(c.name) + '&file=' + encodeURIComponent(r.folder) + '&name=transcript.txt';
      l.target = '_blank'; l.rel = 'noopener'; l.title = 'What the streamer said and the chat, in order, with translations';
      d.append(' · ', l);
    }
    if (SCAN_ACTIVE.includes(sc.state)) {
      const x = el('button', 'linkbtn', 'cancel'); x.title = 'Stop this scan; a waiting YouTube upload goes ahead without it';
      x.onclick = () => scanAction('cancel', c.name, r.folder);
      d.append(' · ', x);
    }
    const key = c.name + '/' + r.folder;
    if (scanErr[key]) d.append(el('div', 'del-err', scanErr[key]));
    return d;
  }
  // ---- AI summary: per recording
  const AI_ACTIVE = ['queued', 'pictures', 'submitted'];
  const AI_TEXT = { queued: 'waiting', pictures: 'taking pictures from the video', submitted: 'Claude is writing it', done: 'ready',
                    failed: 'failed', cancelled: 'cancelled' };
  let openSummary = null;
  const aiErr = {}, aiCache = {};
  const aiSource = ai => ai.source === 'chrome' ? 'Chrome\'s built-in AI (on your PC)' : (ai.model || 'Claude');
  function aiLine(c, r) {
    const ai = r.ai, d = el('div', 'rec-sub');
    let t = 'AI summary: ' + (AI_TEXT[ai.state] || ai.state);
    if (ai.note && AI_ACTIVE.includes(ai.state) && ai.note !== AI_TEXT[ai.state]) t += ' · ' + ai.note;
    if (ai.state === 'failed' && ai.error) t += ' · ' + ai.error;
    d.textContent = t;
    if (AI_ACTIVE.includes(ai.state)) {
      const x = el('button', 'linkbtn', 'cancel'); x.title = 'Stop making this summary';
      x.onclick = () => aiAction('cancel', c.name, r.folder);
      d.append(' · ', x);
    } else if (ai.state === 'failed' || ai.state === 'cancelled') {
      const x = el('button', 'linkbtn', 'try again'); x.onclick = () => summarize(c, r);
      d.append(' · ', x);
    }
    const key = c.name + '/' + r.folder;
    if (aiErr[key]) d.append(el('div', 'del-err', aiErr[key]));
    return d;
  }
  async function aiAction(op, channel, folder, extra) {
    const key = channel + '/' + folder;
    delete aiErr[key];
    try { await post('?aisummary=1', Object.assign({ op, channel, folder }, extra || {})); } catch (e) { aiErr[key] = e.message; }
    await load();
  }
  function summarize(c, r, forcePc) {
    const k = lastData.ai_summary.key;
    if (!forcePc && (k.set || k.pending)) { openSummary = null; aiAction('queue', c.name, r.folder); return; }
    window.open('?ch=' + encodeURIComponent(c.name) + '&scan=' + encodeURIComponent(r.folder) + '&summary=1', '_blank', 'noopener');
  }
  // Markdown from the summary, built as elements (never as HTML): headings, bullets, **bold**, *italic*,
  // and [h:mm:ss] times that open the player at that point. Links stay plain text.
  function mdRender(md, playUrl) {
    const box = el('div', 'md');
    let ul = null;
    const inline = (parent, s) => {
      const re = /(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|\[\d{1,2}:\d{2}:\d{2}\])/g;
      let last = 0, m;
      while ((m = re.exec(s))) {
        if (m.index > last) parent.append(s.slice(last, m.index));
        const tok = m[0];
        if (tok.startsWith('**')) parent.append(el('b', null, tok.slice(2, -2)));
        else if (tok.startsWith('[')) {
          const p = tok.slice(1, -1).split(':').map(Number), secs = p[0] * 3600 + p[1] * 60 + p[2];
          if (playUrl) { const a = el('a', 'ts', tok.slice(1, -1)); a.href = playUrl + '#t=' + secs; a.target = '_blank'; a.rel = 'noopener'; a.title = 'Play from here'; parent.append(a); }
          else parent.append(el('span', 'ts', tok.slice(1, -1)));
        } else parent.append(el('i', null, tok.slice(1, -1)));
        last = re.lastIndex;
      }
      if (last < s.length) parent.append(s.slice(last));
    };
    for (const raw of String(md).split('\n')) {
      const line = raw.replace(/\s+$/, '');
      let m;
      if (!line.trim()) { ul = null; continue; }
      if ((m = /^(#{1,4})\s+(.*)$/.exec(line))) { ul = null; const h = el(m[1].length === 1 ? 'h3' : 'h4'); inline(h, m[2]); box.append(h); continue; }
      if ((m = /^\s*[-*•]\s+(.*)$/.exec(line))) { if (!ul) { ul = el('ul'); box.append(ul); } const li = el('li'); inline(li, m[1]); ul.append(li); continue; }
      ul = null; const p = el('p'); inline(p, line); box.append(p);
    }
    return box;
  }
  function summaryRow(c, r) {
    const key = c.name + '/' + r.folder, ai = r.ai;
    const tr = el('tr', 'sumrow'), td = el('td'); td.colSpan = 2; tr.append(td);
    const body = el('div', 'md'); td.append(body);
    const cached = aiCache[key];
    const playUrl = r.playable ? '?ch=' + encodeURIComponent(c.name) + '&play=' + encodeURIComponent(r.folder) : null;
    const show = text => body.replaceWith(mdRender(text, playUrl));
    if (cached && cached.finished === ai.finished) show(cached.text);
    else {
      body.textContent = 'Loading…';
      fetch('?ch=' + encodeURIComponent(c.name) + '&file=' + encodeURIComponent(r.folder) + '&name=ai_summary.md', { credentials: 'same-origin', cache: 'no-store' })
        .then(x => x.ok ? x.text() : Promise.reject(new Error('HTTP ' + x.status)))
        .then(text => { aiCache[key] = { finished: ai.finished, text }; if (body.isConnected) show(text); })
        .catch(e => { body.textContent = 'Could not load the summary (' + e.message + ').'; });
    }
    const f = el('div', 'sumfoot rec-sub');
    const when = ai.finished ? new Date(ai.finished * 1000).toLocaleString() : '';
    f.append(el('span', null, 'Written by ' + aiSource(ai) + (when ? ' · ' + when : '') + (ai.cost ? ' · about $' + ai.cost.toFixed(4) : '')
      + (ai.pictures ? ' · ' + ai.pictures + ' pictures' : '') + (ai.notes && ai.notes.length ? ' · ' + ai.notes.join('; ') : '')
      + '. AI can get details wrong - the times link to the player so you can check.'));
    const read = el('button', 'act play', 'Read in a window'); read.title = 'Open the summary large, to read it comfortably';
    read.onclick = () => { const x = aiCache[key]; if (x) openReader(c, r, x.text); };
    const again = el('button', 'act', 'Summarize again'); again.onclick = () => summarize(c, r);
    const k = lastData.ai_summary.key;
    const pc = el('button', 'act', 'Summarize on this PC'); pc.title = 'Free, with Chrome\'s built-in AI (shorter, no pictures)'; pc.onclick = () => summarize(c, r, true);
    const del = el('button', 'act del', 'Delete summary'); del.onclick = () => { if (confirm('Delete this summary?')) { openSummary = null; aiAction('delete', c.name, r.folder); } };
    const close = el('button', 'act', 'Close'); close.onclick = () => { openSummary = null; render(lastData); };
    f.append(read, again);
    if (k.set || k.pending) f.append(pc);
    f.append(del, close);
    td.append(f);
    if (aiErr[key]) td.append(el('div', 'del-err', aiErr[key]));
    return tr;
  }

  /** The summary large, over the page; Esc, the ✕ or a click beside it closes it. */
  function openReader(c, r, text) {
    const ov = el('div', 'reader'); ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true');
    const box = el('div', 'rbox');
    const head = el('div', 'rhead');
    head.append(el('span', 'rtitle', (c.name + ' · ' + (r.started ? r.started.slice(0, 10) : '') + ' · ' + (r.title || r.folder))));
    const dl = el('a', 'act', 'Download'); dl.title = 'Save the summary as a text file (Markdown)';
    dl.href = '?ch=' + encodeURIComponent(c.name) + '&file=' + encodeURIComponent(r.folder) + '&name=ai_summary.md&dl=1';
    const x = el('button', 'act', '✕ Close');
    head.append(dl, x);
    const body = el('div', 'rbody');
    body.append(mdRender(text, r.playable ? '?ch=' + encodeURIComponent(c.name) + '&play=' + encodeURIComponent(r.folder) : null));
    box.append(head, body); ov.append(box);
    const close = () => { ov.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', esc); };
    const esc = e => { if (e.key === 'Escape') close(); };
    x.onclick = close;
    ov.addEventListener('click', e => { if (e.target === ov) close(); });
    document.addEventListener('keydown', esc);
    document.body.style.overflow = 'hidden';
    document.body.append(ov);
    x.focus();
  }

  // ---- AI summary: settings card (built once; only its status is refreshed)
  let aiUI = null;
  function buildAi() {
    const card = el('div', 'card ai');
    const head = el('div', 'card-head'); head.append(el('h2', null, 'AI summaries'), el('span', 'updated', 'a written overview of each stream, shown at its recording'));
    card.append(head);
    const body = el('div', 'aibody');
    const status = el('div', 'aistatus');
    const sw = (label, title) => { const l = el('label', 'chk'); const i = el('input'); i.type = 'checkbox'; l.append(i, ' ' + label); l.title = title; return [l, i]; };
    const [autoL, auto] = sw('Summarize each stream automatically', 'After a stream (and after your PC-scan choice), Claude writes the summary');
    const [picL, pics] = sw('Include pictures from the video', 'A picture every few minutes, so Claude also sees what was on screen (a few tenths of a cent extra)');
    const model = el('select'); model.title = 'Which Claude model writes the summaries';
    const r1 = el('div', 'airow'); r1.append(autoL, picL, model);
    const save = async (body) => { try { await post('?aisummary=1', Object.assign({ op: 'settings' }, body)); } catch (e) { status.textContent = e.message; } await load(); };
    auto.onchange = () => save({ auto: auto.checked });
    pics.onchange = () => save({ pictures: pics.checked });
    model.onchange = () => save({ model: model.value });
    const kf = el('form', 'airow'); kf.autocomplete = 'off';
    const ki = el('input'); ki.type = 'password'; ki.placeholder = 'Paste your API key (sk-ant-…)'; ki.spellcheck = false; ki.autocomplete = 'off';
    const kb = el('button', 'act add', 'Save key'); kb.type = 'submit';
    const kr = el('button', 'act del', 'Remove key'); kr.type = 'button';
    const kerr = el('span', 'del-err');
    kf.append(ki, kb, kr, kerr);
    kf.onsubmit = async e => {
      e.preventDefault(); kerr.textContent = '';
      try { await post('?aisummary=1', { op: 'key', key: ki.value.trim() }); ki.value = ''; await load(); } catch (x) { kerr.textContent = x.message; }
    };
    kr.onclick = async () => { if (!confirm('Remove the API key from the server? Summaries can then only be made on your PC.')) return; try { await post('?aisummary=1', { op: 'key_remove' }); } catch (x) { kerr.textContent = x.message; } await load(); };
    const how = el('details');
    how.append(el('summary', null, 'How to set up Claude (once, about 5 minutes)'));
    const ol = el('ol');
    const li = (...parts) => { const x = el('li'); parts.forEach(p => x.append(p)); ol.append(x); };
    const link = (text, href) => { const a = el('a', null, text); a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer'; return a; };
    li('Go to ', link('platform.claude.com', 'https://platform.claude.com'), ' (the Claude Console) and sign in or create an account. This is separate from a claude.ai subscription.');
    li('Under ', link('Settings → Billing', 'https://platform.claude.com/settings/billing'), ', buy a little credit - $5 covers hundreds of summaries with Haiku. (Max and Team plans include monthly API credits you can claim there.) Optional: set a monthly spend limit.');
    li('Under ', link('Settings → API keys', 'https://platform.claude.com/settings/keys'), ', click Create key, name it "Stream Recorder" and copy it.');
    li('Paste it above and click Save key. Within a minute the recorder stores it encrypted (like the Twitch token, so REC_KEY must be in your cron line) and checks that it works; the dashboard never shows it again.');
    how.append(ol);
    const cost = el('p', null, 'What it costs: one summary is sent as a batch job (half price; the result comes back within minutes, at most a day). A 2–4 hour stream with pictures is about 60,000–80,000 tokens: well under 1 cent with Claude Haiku 5.5, roughly 10–20 cents with Claude Sonnet 5.5 (better writing). The exact cost appears under each summary.');
    const priv = el('p', null, 'What is sent: the stream\'s title and categories, what the streamer said (from the PC scan, when done), the chat and the pictures. Under Anthropic\'s commercial terms for the API, it may not use this to train its models. Without a PC scan the summary is based on chat, categories and pictures only.');
    how.append(cost, priv);
    const pc = el('details');
    pc.append(el('summary', null, 'No API key? Summarize on your PC for free'));
    const ul = el('ul');
    ['Click "Summarize" at a recording: the scan page opens and Chrome\'s built-in AI (Gemini Nano, or Phi in Edge) writes the summary on your PC. Nothing is sent anywhere.',
     'Needs Chrome or Edge 138 or newer on Windows, macOS or Linux, a graphics card with more than 4 GB memory (or 16 GB RAM), and about 22 GB free disk space for the model, which Chrome downloads once.',
     'It\'s a smaller model with a short memory: it summarises the stream in 10-minute pieces and then combines those, without pictures. Good for an overview, less detailed than Claude.',
     'It reads English, German, Spanish, French and Japanese; for other languages it uses the English translation from the PC scan.'].forEach(t => ul.append(el('li', null, t)));
    pc.append(ul);
    body.append(status, r1, kf, how, pc);
    card.append(body);
    $('#aiBox').append(card);
    aiUI = { card, status, auto, pics, model, ki, kr, kb };
  }
  function renderAi(data) {
    const ai = data.ai_summary;
    if (!ai || !ai.available) { if (aiUI) aiUI.card.style.display = 'none'; return; }
    if (!aiUI) buildAi();
    aiUI.card.style.display = '';
    const u = aiUI, k = ai.key, a = document.activeElement;
    if (a !== u.model) u.model.replaceChildren(...ai.models.map(m => Object.assign(el('option', null, m.name + (m.id === 'claude-haiku-5-5' ? ' - cheapest (recommended)' : ' - better writing, costs more')), { value: m.id })));
    u.model.value = ai.settings.model;
    u.auto.checked = ai.settings.auto && (k.set || k.pending);
    u.pics.checked = ai.settings.pictures;
    let cls = 'warn', t;
    if (k.pending) t = 'API key received - the next cron run (within a minute) stores it encrypted and checks it.';
    else if (k.set && k.ok === true) { cls = 'ok'; t = '✓ Claude writes the summaries (key ' + (k.hint || '') + ' works' + (ai.spent ? ', spent so far about $' + ai.spent.toFixed(4) : '') + ').'; }
    else if (k.set && k.ok === false) { cls = 'bad'; t = '✗ ' + (k.error || 'The API key does not work.'); }
    else if (k.set) t = 'API key stored' + (k.hint ? ' (' + k.hint + ')' : '') + (k.error ? ' - ' + k.error : ' - not checked yet.');
    else t = 'No API key yet: summaries are made on your PC with Chrome\'s built-in AI. For better summaries with pictures, set up Claude below.';
    u.status.className = 'aistatus ' + cls; u.status.textContent = t;
    u.kr.style.display = k.set || k.pending ? '' : 'none';
    u.kb.textContent = k.set ? 'Replace key' : 'Save key';
    u.auto.disabled = !(k.set || k.pending);
    u.auto.parentNode.title = u.auto.disabled ? 'Needs an API key (automatic summaries are made by Claude on the server)' : 'After a stream (and after your PC-scan choice), Claude writes the summary';
  }

  async function scanAction(op, channel, folder) {
    const key = channel + '/' + folder;
    delete scanErr[key];
    try { await post('?scanapi=1', { op, channel, folder }); } catch (e) { scanErr[key] = e.message; }
    await load();
  }
  function openScan(channel, folder, request) {
    // Open the tab first (a pop-up blocker allows it right after the click), then tell the server.
    window.open('?ch=' + encodeURIComponent(channel) + '&scan=' + encodeURIComponent(folder), '_blank', 'noopener');
    if (request) scanAction('request', channel, folder);
  }
  $('#toTop').onclick = () => window.scrollTo({ top: 0, behavior: 'smooth' });
  // ---- log out, and one-time notices
  $('#logoutBtn').onclick = async () => { try { await post('?logout=1', { op: 'this' }); } catch (e) {} location.reload(); };
  $('#logoutAllBtn').onclick = async () => {
    if (!confirm('End every login to this dashboard, on all devices and browsers (including this one)?')) return;
    try { await post('?logout=1', { op: 'all' }); } catch (e) {}
    location.reload();
  };
  function renderNotices(data) {
    const box = $('#notices'); box.replaceChildren();
    if (data.webroot_risk) {
      const n = el('div', 'notice bad');
      n.append(el('span', null, 'The recorder folder is inside your website\'s folder. Recordings and the secret/ folder could be downloadable on servers that ignore .htaccess (e.g. nginx). Move it next to public_html (docs/install-webserver.md).'));
      box.append(n);
    }
    if (data.key_file) {
      const n = el('div', 'notice info');
      n.append(el('span', null, 'A plain-text copy of your dashboard key is still on the server (secret/dashboard_key.txt). Once you have saved the key somewhere safe, delete that copy; logging in keeps working.'));
      const b = el('button', 'act', 'I saved my key, delete the copy');
      b.onclick = async () => { try { await post('?keyfile=1', {}); load(); } catch (e) { alert(e.message); } };
      n.append(b);
      box.append(n);
    }
  }

  function renderScanAsks(data) {
    const box = $('#scanAsks'); box.replaceChildren();
    const asks = [];
    data.channels.forEach(c => c.recordings.forEach(r => { if (r.scan && r.scan.state === 'ask') asks.push([c, r]); }));
    if (!asks.length) return;
    const card = el('div', 'card scanask');
    const head = el('div', 'card-head');
    head.append(el('h2', null, 'Scan on your PC?'), el('span', 'updated', 'subtitles, translation and spoken-word moments'));
    if (asks.length > 1) head.append(el('span', 'badge badge-new', asks.length + ' streams'));
    card.append(head);
    asks.forEach(([c, r]) => {
      const row = el('div', 'askrow');
      const mid = el('div');
      mid.style.minWidth = '0';
      mid.append(el('div', 'who', c.name));
      const subj = el('div', 'subj', r.title || r.folder); subj.title = r.title || r.folder;
      mid.append(subj);
      const meta = el('div', 'meta', (r.started ? r.started.slice(0, 16).replace('-', '/').replace('-', '/') : r.folder) + (r.seconds ? ' · ' + dur(r.seconds) : ''));
      if (c.youtube) { meta.append(' · '); meta.append(el('span', 'wait', 'YouTube upload (' + c.youtube + ') waits for your choice')); }
      mid.append(meta);
      const key = c.name + '/' + r.folder;
      if (scanErr[key]) mid.append(el('div', 'del-err', scanErr[key]));
      const btns = el('div', 'acts');
      const go = el('button', 'act primary', 'Scan on this PC'); go.onclick = () => openScan(c.name, r.folder, true);
      const no = el('button', 'act', 'Skip'); no.title = 'No scan; a YouTube upload goes ahead with the chat-based moments';
      no.onclick = () => scanAction('skip', c.name, r.folder);
      btns.append(go, no);
      row.append(mid, btns);
      card.append(row);
    });
    box.append(card);
  }

  async function ytAction(body, key) {
    ytBusy = true; ytError = null; ytCancelErr = null; pendingYt = body.op === 'queue' ? key : null; render(lastData);
    try {
      await post('?upload=1', body);
      pendingYt = null;
    } catch (e) {
      ytError = e.message;
      if (body.op === 'cancel') { ytCancelErr = { key, msg: e.message }; pendingYt = null; }
    }
    ytBusy = false;
    await load();
  }

  async function doDelete(channel, folder) {
    deleting = true; deleteError = null; render(lastData);
    try {
      await post('?delete=1', { channel, folder });
      pendingDelete = null;
      deleting = false;
      await load();
    } catch (e) {
      deleting = false;
      deleteError = e.message;
      render(lastData);
    }
  }

  function render(data) {
    lastData = data;
    renderMoments(data);
    renderAi(data);
    renderNotices(data);
    renderScanAsks(data);
    const now = data.now;
    const freshAlerts = renderAlerts(data.alerts, now);
    $('#updated').textContent = 'Updated ' + new Date(now * 1000).toLocaleTimeString() + ' · every 10 s';
    const chip = (id, cls, k, v, title) => { const c = $(id); c.className = 'chip ' + cls; c.replaceChildren(el('span', 'dot'), el('span', 'k', k), el('span', null, v)); c.title = title; };
    const tt = data.twitch_token;
    if (tt) chip('#hToken', tt.status === 'valid' ? 'ok' : 'bad', 'Twitch token', tt.status === 'valid' ? 'working' : 'not working',
      (tt.detail || '') + ' · checked ' + ago(tt.checked, now));
    else chip('#hToken', 'warn', 'Twitch token', 'not set', 'No token: recording anonymously (ads are cut out)');
    if (data.last_cron_age != null) {
      const age = data.last_cron_age;
      chip('#hCron', age > 180 ? 'bad' : 'ok', 'Cron', age > 180 ? 'stopped? ' + ago(now - age, now) : ago(now - age, now),
        age > 180 ? 'No cron run for ' + Math.round(age / 60) + ' min - check the cron job or start script' : 'Last cron run ' + (data.last_cron || '').slice(0, 19));
    }

    const strip = $('#strip'); strip.replaceChildren();
    const live = data.channels.filter(c => c.recording).length;
    strip.append(tile('Recording now', live + ' of ' + data.channels.length, live ? data.channels.filter(c => c.recording).map(c => c.name).join(', ') : 'all channels offline', live ? 'live' : ''));
    if (freshAlerts) strip.append(tile('Alerts', freshAlerts + ' new', (data.alerts[0] || {}).subject ? data.alerts[0].subject.replace(/^\[Recorder\]\s*/, '') : '', 'warn'));
    const ya = data.youtube_accounts;
    strip.append(tile('YouTube', ya.length ? ya.map(a => a.name).join(', ') : 'No accounts',
      ya.some(a => a.error) ? ya.filter(a => a.error).map(a => a.name + ': ' + a.error).join(' · ') : (data.queues.upload ? data.queues.upload + ' upload(s) queued' : 'nothing queued'),
      ya.some(a => a.error) ? 'warn' : ''));
    const active = [];
    data.channels.forEach(c => c.recordings.forEach(r => {
      if (r.convert && r.convert.state === 'running') active.push(['Converting', (r.convert.pct != null ? r.convert.pct.toFixed(0) + '%' : '…'), c.name + ' · ' + (r.title || r.folder)]);
      if (r.upload && !r.upload.error && r.upload.size) active.push(['Uploading to YouTube', Math.floor(100 * r.upload.offset / r.upload.size) + '%', c.name + ' · ' + (r.title || r.folder)]);
    }));
    active.forEach(([label, val, sub]) => strip.append(tile(label, val, sub, 'ok')));
    const pr = data.processes;
    if (pr) {
      const nc = pr.convert.length;
      const runs = pr.recorder + ' recorder run' + (pr.recorder === 1 ? '' : 's');
      strip.append(tile('Server processes', nc + (nc === 1 ? ' conversion' : ' conversions'),
        nc > 1 ? 'more than one conversion! ' + pr.convert.map(p => p.channel + ' ' + p.minutes + ' min').join(', ')
          : nc ? pr.convert[0].channel + ' · ' + pr.convert[0].minutes + ' min · ' + runs
          : runs + (pr.other_ffmpeg ? ' · ' + pr.other_ffmpeg + ' other ffmpeg' : ''),
        nc > 1 ? 'bad' : ''));
    }
    strip.append(tile('Storage', mb(data.disk.recordings), data.disk.free != null ? mb(data.disk.free) + ' free on server' : (data.queues.convert ? data.queues.convert + ' conversion(s) queued' : '')));

    const box = $('#channels'); box.replaceChildren();
    if (!data.channels.length) { box.append(el('div', 'empty', 'No streamers yet - add one above.')); return; }
    data.channels.forEach(c => {
      const folded = !!foldState()[c.name];
      const card = el('div', 'card' + (folded ? ' folded' : ''));
      const head = el('div', 'card-head');
      head.title = folded ? 'Click to unfold' : 'Click to fold';
      head.addEventListener('click', e => {
        if (e.target.closest('a')) return;
        const st = foldState(); st[c.name] = !card.classList.contains('folded');
        if (!st[c.name]) delete st[c.name];
        saveFoldState(st);
        card.classList.toggle('folded', !!st[c.name]);
        head.title = st[c.name] ? 'Click to unfold' : 'Click to fold';
      });
      head.append(el('span', 'chev', '\u25BE'));
      const sw = el('button', 'switch' + (c.enabled ? ' on' : ''));
      sw.setAttribute('role', 'switch'); sw.setAttribute('aria-checked', String(c.enabled));
      sw.title = c.enabled ? 'Recording is ON - click to switch off' : 'Recording is OFF - click to switch on';
      sw.append(el('i'));
      sw.addEventListener('click', e => {
        e.stopPropagation();
        if (c.enabled && c.recording) { pendingStop = c.name; render(lastData); return; } // ask first
        streamerAction(c.name, { op: 'set', enabled: !c.enabled });
      });
      head.append(sw);
      head.append(el('h2', null, c.name));
      head.append(!c.enabled ? (c.wrapping_up ? el('span', 'badge b-warn', 'Stopping…') : el('span', 'badge b-off', 'Off'))
        : c.recording ? el('span', 'badge b-live', 'Recording')
        : c.not_found ? el('span', 'badge b-warn', 'Not found on Twitch')
        : c.stale ? el('span', 'badge b-warn', 'No sign of life')
        : el('span', 'badge b-idle', 'Waiting for stream'));
      if (c.enabled || c.recording) head.append(el('span', 'updated', (c.recording ? 'last activity ' : 'last checked ') + ago(c.heartbeat, now)));
      // Shown only while folded: the gist in one line.
      const busy = c.recordings.filter(r => r.convert || r.upload).map(r =>
        r.convert ? (r.convert.state === 'running' ? 'converting' + (r.convert.pct != null ? ' ' + r.convert.pct.toFixed(0) + '%' : '') : 'conversion ' + r.convert.state)
                  : 'uploading ' + (r.upload.size ? Math.floor(100 * r.upload.offset / r.upload.size) + '%' : ''));
      head.append(el('span', 'fold-sum', [c.recording ? 'recording ' + dur(c.seconds) : null,
        c.recording_count + (c.recording_count === 1 ? ' recording' : ' recordings')].concat(busy).filter(Boolean).join(' · ')));
      card.append(head);
      if (pendingStop === c.name) {
        const cf = el('div', 'stopconfirm');
        cf.append(el('span', null, 'Stop recording ' + c.name + ' now? What has been recorded so far is kept and converted like a normal recording.'));
        const yes = el('button', 'act del sure', 'Stop recording'); yes.onclick = () => streamerAction(c.name, { op: 'set', enabled: false });
        const no = el('button', 'act', 'Cancel'); no.onclick = () => { pendingStop = null; render(lastData); };
        cf.append(yes, no); card.append(cf);
      }
      if (streamerError[c.name]) card.append(el('div', 'del-err pad', streamerError[c.name]));

      // Settings for this streamer
      const set = el('div', 'settings');
      const yl = el('label', null, 'Upload to YouTube ');
      const ys = el('select');
      [['', 'No upload']].concat(data.youtube_accounts.map(a => [a.name, a.name])).forEach(([v, t]) => { const o = el('option', null, t); o.value = v; ys.append(o); });
      if (c.youtube && !data.youtube_accounts.some(a => a.name === c.youtube)) { const o = el('option', null, c.youtube + ' (not set up)'); o.value = c.youtube; ys.append(o); }
      ys.value = c.youtube || '';
      ys.onchange = () => streamerAction(c.name, { op: 'set', youtube: ys.value });
      yl.append(ys);
      const tl = el('label', null, 'Your Twitch login ');
      const ts = el('select');
      [['auto', 'Auto (where you are ad-free)'], ['on', 'Always'], ['off', 'Never (anonymous)']].forEach(([v, t]) => { const o = el('option', null, t); o.value = v; ts.append(o); });
      ts.value = c.twitch_auth || 'auto';
      ts.onchange = () => streamerAction(c.name, { op: 'set', twitch_auth: ts.value });
      tl.append(ts);
      const pl = el('label', null, 'PC scan after each stream ');
      const ps = el('select');
      [['off', 'Off'], ['ask', 'Ask me']].forEach(([v, t]) => { const o = el('option', null, t); o.value = v; ps.append(o); });
      ps.value = c.pc_scan || 'off';
      ps.title = 'Subtitles, translation and spoken-word moments, made on your PC. With "Ask me" a YouTube upload waits for your choice.';
      ps.onchange = () => streamerAction(c.name, { op: 'set', pc_scan: ps.value });
      pl.append(ps);
      set.append(yl, tl, pl);
      if (c.listed) {
        if (pendingRemove === c.name) {
          const w = el('span', 'rmconfirm', 'Remove ' + c.name + ' from the list?' + (c.recording_count ? ' Its recordings stay (delete them below).' : ''));
          const y = el('button', 'act del sure', 'Remove'); y.onclick = () => streamerAction(c.name, { op: 'remove' });
          const n = el('button', 'act', 'Cancel'); n.onclick = () => { pendingRemove = null; render(lastData); };
          w.append(y, n); set.append(w);
        } else {
          const rm = el('button', 'act del', 'Remove streamer');
          if (c.enabled) { rm.disabled = true; rm.title = 'Switch recording off first'; }
          else rm.onclick = () => { pendingRemove = c.name; render(lastData); };
          set.append(rm);
        }
      }
      card.append(set);

      if (c.recording || c.stale) {
        const n = el('div', 'now');
        const t = el('div', 'title'); t.textContent = c.title || '(no title)'; if (c.game) { const g = el('span', null, ' · ' + c.game); t.append(g); }
        n.append(t, stat('Recorded', dur(c.seconds)), stat('Size', mb(c.bytes)), stat('Chat lines', String(c.chat)),
          stat('Gaps', String(c.gaps)), stat('Ads skipped', String(c.ads)), stat('Cron runs', c.runs + (c.kills ? ' (' + c.kills + ' killed)' : '')));
        if (c.viewer) n.append(stat('Watching as', c.viewer));
        card.append(n);
      }

      const cols = el('div', 'cols');
      const recs = el('div', 'section'); recs.append(el('h3', null, 'Recordings (' + c.recording_count + ')'));
      const table = el('table', 'rectable');
      if (!c.recordings.length) recs.append(el('div', 'rec-sub', 'None yet.'));
      c.recordings.forEach(r => {
        const tr = el('tr'); const a = el('td'); const b = el('td', 'r');
        a.append(el('div', 'rec-title', r.title || r.folder));
        const sub = [r.started ? r.started.slice(0, 16) : r.folder, r.seconds ? dur(r.seconds) : null, Object.keys(r.files).filter(f => f !== 'chat.txt').join(', ') || 'no video', r.files['chat.txt'] ? 'chat' : null].filter(Boolean).join(' · ');
        a.append(el('div', 'rec-sub', sub));
        if (r.convert) {
          const c = r.convert;
          if (c.state === 'running' && c.pct != null) {
            const bar = el('div', 'bar convert'); const i = el('i'); i.style.width = c.pct + '%'; bar.append(i); a.append(bar);
          }
          a.append(el('div', 'rec-sub', 'MP4 conversion: ' + (c.state === 'running'
            ? (c.pct != null ? c.pct.toFixed(0) + '%' : 'running') + (c.eta_min != null ? ' · ~' + c.eta_min + ' min left' : '') + (c.attempt > 1 ? ' · attempt ' + c.attempt : '')
            : c.state)));
          if (c.youtube) a.append(el('div', 'rec-sub', 'Then uploaded to YouTube (' + c.youtube + ')'));
        }
        if (r.upload) {
          const pct = r.upload.size ? Math.floor(100 * r.upload.offset / r.upload.size) : 0;
          if (r.upload.started || r.upload.error) { const bar = el('div', 'bar'); const i = el('i'); i.style.width = pct + '%'; bar.append(i); a.append(bar); }
          const scanWait = r.scan && (r.scan.state === 'ask' || SCAN_ACTIVE.includes(r.scan.state)) && !r.upload.started;
          a.append(el('div', 'rec-sub', 'YouTube (' + r.upload.account + '): ' + (r.upload.started || r.upload.error ? pct + '%' : scanWait ? 'waits for the PC scan' : 'queued, starts at the next cron run when nothing is recording') + (r.upload.error ? ' · ' + r.upload.error : '')));
        }
        if (r.scan) a.append(scanLine(c, r));
        if (r.ai && (r.ai.state !== 'done' || !r.ai.has_text)) a.append(aiLine(c, r));
        if (r.youtube) { const l = el('a', null, 'YouTube (' + r.youtube.privacy + ')'); l.href = r.youtube.url; l.target = '_blank'; l.rel = 'noopener noreferrer'; const d = el('div', 'rec-sub'); d.append(l); a.append(d); }
        b.append(el('span', 'pill p-' + r.status.replace(' ', '-'), r.status)); b.append(el('div', 'rec-sub', mb(r.size)));
        const key = c.name + '/' + r.folder;
        const acts = el('div', 'acts');
        if (r.playable) {
          const pl = el('a', 'act play', '\u25B6 Play');
          pl.href = '?ch=' + encodeURIComponent(c.name) + '&play=' + encodeURIComponent(r.folder);
          pl.target = '_blank'; pl.rel = 'noopener';
          pl.title = 'Watch with chat in a new tab';
          acts.append(pl);
        }
        const queuedYt = r.upload || (r.convert && r.convert.youtube);
        if (queuedYt) {
          const cu = el('button', 'act', 'Cancel upload');
          cu.title = 'Take this recording out of the YouTube upload queue';
          cu.onclick = () => ytAction({ op: 'cancel', channel: c.name, folder: r.folder }, key);
          acts.append(cu);
          if (ytCancelErr && ytCancelErr.key === key) a.append(el('div', 'del-err', ytCancelErr.msg));
        } else if (!queuedYt && data.youtube_accounts.length && r.status !== 'recording' && r.status !== 'interrupted' && Object.keys(r.files).some(f => f.startsWith('video'))) {
          const yb = el('button', 'act', r.youtube ? 'Upload again' : 'YouTube');
          yb.title = r.youtube ? 'Already on YouTube - upload it once more' : 'Upload this recording to YouTube';
          yb.onclick = () => { pendingYt = key; ytError = null; pendingDelete = null; render(lastData); };
          acts.append(yb);
        }
        if (r.scan && SCAN_ACTIVE.includes(r.scan.state)) {
          const so = el('button', 'act', 'Open scan'); so.title = 'Open the scan page (it continues where it stopped)';
          so.onclick = () => openScan(c.name, r.folder, false);
          acts.append(so);
        } else if (r.status !== 'recording' && r.status !== 'interrupted' && Object.keys(r.files).some(f => f.startsWith('video'))) {
          const sb = el('button', 'act', r.scan && r.scan.state === 'done' ? 'Scan again' : 'Scan');
          sb.title = 'Subtitles, translation and spoken-word moments, made on this PC (Chrome or Edge)';
          sb.onclick = () => openScan(c.name, r.folder, true);
          acts.append(sb);
        }
        if (data.ai_summary && data.ai_summary.available) {
          if (r.ai && r.ai.has_text) {
            const sb = el('button', 'act' + (openSummary === key ? ' on' : ''), 'Summary');
            sb.title = 'Read the AI summary of this stream';
            sb.onclick = () => { openSummary = openSummary === key ? null : key; render(lastData); };
            acts.append(sb);
          } else if (!(r.ai && AI_ACTIVE.includes(r.ai.state)) && r.status !== 'recording' && r.status !== 'interrupted' && (r.files['chat.txt'] || r.playable)) {
            const sb = el('button', 'act', 'Summarize');
            sb.title = data.ai_summary.key.set || data.ai_summary.key.pending ? 'Let Claude write a summary of this stream' : 'Write a summary on this PC with Chrome\'s built-in AI (no API key set)';
            sb.onclick = () => summarize(c, r);
            acts.append(sb);
          }
        }
        const del = el('button', 'act del', 'Delete');
        if (r.busy) { del.disabled = true; del.title = "Can't delete now: " + r.busy; }
        else del.onclick = () => { pendingDelete = key; deleteError = null; pendingYt = null; render(lastData); };
        acts.append(del);
        b.append(acts);
        tr.append(a, b); table.append(tr);
        if (openSummary === key && r.ai && r.ai.has_text) table.append(summaryRow(c, r));
        if (pendingYt === key) {
          const ctr = el('tr', 'confirm yt'); const td = el('td'); td.colSpan = 2;
          td.append(el('span', null, (r.youtube ? 'Already on YouTube (' + r.youtube.account + '). Upload "' : 'Upload "') + (r.title || r.folder) + '" to'));
          const sel = el('select');
          data.youtube_accounts.forEach(acc => { const o = el('option', null, acc.name + (acc.error ? ' (problem)' : '')); o.value = acc.name; sel.append(o); });
          sel.value = data.youtube_accounts.some(acc => acc.name === c.youtube) ? c.youtube : data.youtube_accounts[0].name;
          const go = el('button', 'act play', ytBusy ? 'Queuing…' : 'Upload');
          go.disabled = ytBusy;
          go.onclick = () => ytAction({ op: 'queue', channel: c.name, folder: r.folder, account: sel.value }, key);
          const no = el('button', 'act', 'Cancel');
          no.onclick = () => { pendingYt = null; ytError = null; render(lastData); };
          td.append(sel, go, no);
          td.append(el('div', 'rec-sub', 'Same title, description and moments as the automatic upload. It starts at the next cron run when nothing is recording' + (r.convert ? ', after the MP4 conversion' : '') + '.'));
          if (ytError) td.append(el('div', 'del-err', ytError));
          ctr.append(td); table.append(ctr);
        }
        if (pendingDelete === key) {
          const ctr = el('tr', 'confirm'); const td = el('td'); td.colSpan = 2;
          td.append(el('span', null, 'Delete "' + (r.title || r.folder) + '" (' + mb(r.size) + ')? Video, chat and everything else in this folder will be gone for good. '));
          const yes = el('button', 'act del sure', deleting ? 'Deleting…' : 'Yes, delete');
          yes.disabled = deleting;
          yes.onclick = () => doDelete(c.name, r.folder);
          const no = el('button', 'act', 'Cancel');
          no.onclick = () => { pendingDelete = null; deleteError = null; render(lastData); };
          td.append(yes, no);
          if (deleteError) td.append(el('div', 'del-err', deleteError));
          ctr.append(td); table.append(ctr);
        }
      });
      recs.append(table);

      const log = el('div', 'section');
      const lh = el('h3', 'loghead', 'Recent log');
      if (c.log_size) {
        const links = el('span', 'loglinks');
        [['Full log', '&quiet=1', 'The whole log without the routine "offline" lines'], ['everything', '', 'The whole log, every line'], ['download', '&dl=1', 'Save the whole log as a file']]
          .forEach(([t, q, tip]) => { const a = el('a', null, t); a.href = '?log=' + encodeURIComponent(c.name) + q; a.target = '_blank'; a.rel = 'noopener'; a.title = tip; links.append(a); });
        links.append(el('span', 'muted', c.log_size < 1048576 ? Math.max(1, Math.round(c.log_size / 1024)) + ' KB' : mb(c.log_size)));
        if (c.log_old_size) {
          const o = el('a', null, 'older part'); o.href = '?log=' + encodeURIComponent(c.name) + '&old=1&quiet=1'; o.target = '_blank'; o.rel = 'noopener';
          o.title = 'The log before its last rotation (' + mb(c.log_old_size) + ')';
          links.append(o);
        }
        lh.append(links);
      }
      log.append(lh);
      const lg = el('div', 'log'); c.log.slice().reverse().forEach(l => lg.append(logLine(l))); log.append(lg);
      cols.append(recs, log); card.append(cols);
      box.append(card);
    });
  }

  async function load() {
    try {
      const r = await fetch('?api=1', { credentials: 'same-origin', cache: 'no-store' });
      if (r.status === 401) { location.reload(); return; } // logged out: show the login form
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const d = await r.json();
      lastData = d;
      const a = document.activeElement;
      if (!(a && a.tagName === 'SELECT' && a.closest('#channels'))) render(d); // don't close a list someone is choosing from
      $('#err').style.display = 'none';
    } catch (e) {
      $('#err').textContent = 'Could not refresh (' + e.message + '). Retrying…';
      $('#err').style.display = 'block';
    }
  }
  document.addEventListener('focusout', e => { if (e.target.tagName === 'SELECT' && lastData) setTimeout(() => render(lastData), 0); });
  load();
  setInterval(load, 10000);
  loadIo();
  setInterval(loadIo, 60000);
})();
</script>
</body>
</html>

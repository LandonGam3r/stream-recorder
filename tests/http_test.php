<?php
/**
 * Dashboard over HTTP: login, cookies, security headers, action tokens, other sites, odd names.
 * Starts the dashboard with PHP's own web server on a free port, against a temporary recorder folder.
 *
 *   php tests/http_test.php
 */
error_reporting(E_ALL);
$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/sr-http-' . bin2hex(random_bytes(4));
$rec = "$tmp/server";
mkdir("$rec/secret", 0700, true);
foreach (glob("$repo/server/*.php") as $f) if (basename($f) !== 'config.php') copy($f, "$rec/" . basename($f));
$key = 'TestDashboardKey0123456789';
file_put_contents("$rec/secret/dashboard_key.txt", $key);
// One recording with a little of everything.
$d = "$rec/recordings/somestreamer/2026-11-27_200000_1";
mkdir("$d/clips", 0755, true);
file_put_contents("$d/meta.json", json_encode(['title' => '<img src=x onerror=alert(1)>', 'stream_started' => '2026-11-27 20:00:00', 'video_start' => '2026-11-27 20:00:00.000', 'stream_ended' => '2026-11-27 21:00:00']));
file_put_contents("$d/chat.txt", "[2026-11-27 20:00:05] alice: hello <script>alert(1)</script>\n");
file_put_contents("$d/video.mp4", str_repeat('x', 1000));
file_put_contents("$d/ai_extras.json", json_encode(['clips' => [[0, 10, 'A clip']]]));
// A second recorder folder that was never run: the first-time page.
mkdir("$tmp/fresh", 0755, true);
foreach (glob("$repo/server/*.php") as $f) if (basename($f) !== 'config.php') copy($f, "$tmp/fresh/" . basename($f));

$fails = [];
$pass = 0;
$check = function ($cond, string $what) use (&$fails, &$pass) { if ($cond) $pass++; else $fails[] = $what; };

function serve(string $recDir, string $docroot, int &$port)
{
    for ($i = 0; $i < 20; $i++) {
        $port = random_int(20000, 40000);
        $env = array_merge(getenv(), ['RECORDER_DIR' => $recDir]);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        for ($t = 0; $t < 30; $t++) { usleep(100000); if (@fsockopen('127.0.0.1', $port)) return $p; }
        proc_terminate($p);
    }
    fwrite(STDERR, "could not start the test web server\n");
    exit(1);
}

/** [status, headers (lower-case name => [values]), body] */
function req(int $port, string $method, string $path, array $headers = [], ?string $body = null, string $cookie = ''): array
{
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIE => $cookie,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
            if (strpos($line, ':') !== false) { [$n, $v] = explode(':', $line, 2); $h[strtolower(trim($n))][] = trim($v); }
            return strlen($line);
        }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $h, $out];
}

$port = 0;
$srv = serve($rec, "$repo/dashboard", $port);
try {
    // ---- not logged in
    [$c, $h, $b] = req($port, 'GET', '/');
    $check($c === 200 && strpos($b, 'Dashboard key') !== false, 'login page');
    $csp = $h['content-security-policy'][0] ?? '';
    $check(strpos($csp, "default-src 'none'") !== false && strpos($csp, "frame-ancestors 'none'") !== false && strpos($csp, "script-src 'nonce-") !== false, 'strict content security policy');
    $check(($h['x-frame-options'][0] ?? '') === 'DENY' && ($h['x-content-type-options'][0] ?? '') === 'nosniff' && ($h['referrer-policy'][0] ?? '') === 'no-referrer', 'security headers');
    foreach (['/?api=1' => 401, '/?search=1&q=hello' => 404, '/?ch=somestreamer&file=2026-11-27_200000_1&name=chat.txt' => 404,
        '/?ch=somestreamer&text=2026-11-27_200000_1' => 200, '/?log=_system' => 404] as $u => $want) {
        [$c, , $b] = req($port, 'GET', $u);
        $check($c === $want && strpos($b, 'alice') === false, "logged out: $u gives $want and nothing of the recording (got $c)");
    }
    [$c, , $b] = req($port, 'POST', '/?bookmarks=1', ['Content-Type: application/json'], '{"op":"add"}');
    $check($c === 404, 'logged out: actions are refused');

    // ---- logging in
    [$c, , $b] = req($port, 'POST', '/', ['Content-Type: application/x-www-form-urlencoded'], 'key=wrong');
    $check(strpos($b, 'Dashboard key') !== false && !isset($h['set-cookie']), 'wrong key: no login');
    [$c, $h, $b] = req($port, 'POST', '/', ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://evil.example'], 'key=' . $key);
    $check($c === 403, 'login from another site is refused');
    [$c, $h, $b] = req($port, 'POST', '/', ['Content-Type: application/x-www-form-urlencoded'], 'key=' . $key);
    $sc = implode("\n", $h['set-cookie'] ?? []);
    $check(preg_match('/recdash2=([^;]+)/', $sc, $m) === 1, 'right key: logged in');
    $check(stripos($sc, 'httponly') !== false && stripos($sc, 'samesite=strict') !== false, 'cookie is HttpOnly and SameSite=Strict');
    $cookie = 'recdash2=' . ($m[1] ?? '');
    [$c, , $page] = req($port, 'GET', '/', [], null, $cookie);
    $check($c === 200 && preg_match('/name="dash-token" content="([a-f0-9]{64})"/', $page, $t) === 1, 'dashboard with its action token');
    $tok = $t[1] ?? '';
    $check(strpos($page, $key) === false, 'the key itself is never in the page');

    // ---- actions need the token and this site
    $act = function (string $path, array $body, array $extra = []) use ($port, $cookie, &$tok) {
        return req($port, 'POST', $path, array_merge(['Content-Type: application/json', "X-Dashboard-Token: $tok"], $extra), json_encode($body), $cookie);
    };
    $rec1 = ['channel' => 'somestreamer', 'folder' => '2026-11-27_200000_1'];
    [$c] = req($port, 'POST', '/?bookmarks=1', ['Content-Type: application/json'], json_encode($rec1 + ['op' => 'add', 't' => 5]), $cookie);
    $check($c === 403, 'no action token: refused');
    [$c] = $act('/?bookmarks=1', $rec1 + ['op' => 'add', 't' => 5], ['Origin: https://evil.example']);
    $check($c === 403, 'other site: refused');
    [$c] = req($port, 'GET', '/?bookmarks=1', [], null, $cookie);
    $check($c !== 200, 'GET does not change anything');
    [$c, , $b] = $act('/?bookmarks=1', $rec1 + ['op' => 'add', 't' => 5, 'note' => '<b>hi</b>']);
    $check($c === 200 && strpos($b, '"ok":true') !== false, 'bookmark with token: works');

    // ---- odd names never reach other folders
    foreach ([['../somestreamer', '2026-11-27_200000_1'], ['somestreamer', '../../secret'], ['kick-..', 'x'], ['youtube-../x', 'x'], ['somestreamer', '2026-11-27_200000_1/..'], ['', '']] as [$ch, $fo]) {
        [$c] = $act('/?footage=1', ['channel' => $ch, 'folder' => $fo, 'op' => 'delete']);
        $check($c === 404 || $c === 400, "footage: '$ch' / '$fo' refused (got $c)");
        [$c] = req($port, 'GET', '/?ch=' . rawurlencode($ch) . '&file=' . rawurlencode($fo) . '&name=meta.json', [], null, $cookie);
        $check($c === 404, "file: '$ch' / '$fo' refused");
    }
    foreach (['../secret/dashboard_key.txt', 'clips/../meta.json', 'clips/abc.mp4', '/etc/passwd', 'secret/dashboard_key.txt'] as $n) {
        [$c] = req($port, 'GET', '/?ch=somestreamer&file=2026-11-27_200000_1&name=' . rawurlencode($n), [], null, $cookie);
        $check($c === 404, "file name '$n' refused");
    }
    [$c, , $b] = req($port, 'GET', '/?ch=somestreamer&file=2026-11-27_200000_1&name=chat.txt', [], null, $cookie);
    $check($c === 200 && strpos($b, 'alice') !== false, 'an allowed file is served');
    [$c, , $b] = $act('/?aiextra=1', $rec1 + ['op' => 'clip', 'index' => 0, 'kind' => 'short', 'upload' => '../../secret/x']);
    $check($c === 400, 'clip upload to an unknown account name: refused');
    [$c] = $act('/?aiextra=1', $rec1 + ['op' => 'digest_read', 'week' => '../../x']);
    $check($c === 404, 'digest with an odd week: refused');
    [$c] = req($port, 'GET', '/?search=1&q=a', [], null, $cookie);
    $check($c === 400, 'search: too short');
    [$c, , $b] = req($port, 'GET', '/?search=1&q=hello', [], null, $cookie);
    $check($c === 200 && strpos($b, '"kind":"chat"') !== false, 'search works');
    [$c] = req($port, 'GET', '/?search=1&q=hello&in=' . rawurlencode('../x'), [], null, $cookie);
    $check($c === 400, 'search in an odd streamer: refused');
    [$c] = $act('/?streamers=1', ['op' => 'add', 'channel' => 'https://evil.example/../x']);
    $check($c === 400, 'add streamer: a link to another site is refused');

    // ---- pages that show outside text keep it as text
    [$c, , $b] = req($port, 'GET', '/?ch=somestreamer&text=2026-11-27_200000_1', [], null, $cookie);
    $check($c === 200 && strpos($b, '<img src=x') === false && strpos($b, '\u003Cimg src=x') !== false, 'text view: the title is data, not HTML');
    $csp = '';
    [$c, $h] = req($port, 'GET', '/?ch=somestreamer&play=2026-11-27_200000_1', [], null, $cookie);
    $csp = $h['content-security-policy'][0] ?? '';
    $check($c === 200 && strpos($csp, 'img-src data: blob: https://static-cdn.jtvnw.net') !== false && strpos($csp, "default-src 'none'") !== false, 'player: emote sites only for pictures');
} finally {
    proc_terminate($srv);
}

// ---- a fresh install shows the first-time page, with nothing secret in it
$srv = serve("$tmp/fresh", "$repo/dashboard", $port);
try {
    [$c, , $b] = req($port, 'GET', '/');
    $check($c === 503 && strpos($b, 'almost there') !== false && strpos($b, 'REC_KEY=') !== false, 'first-time page');
    $check(strpos($b, 'dashboard_key.txt') !== false && !preg_match('/REC_KEY=[A-Za-z0-9]{16}/', $b), 'no key made by the server in it');
    [$c] = req($port, 'GET', '/?api=1');
    $check($c === 503, 'nothing else works before the key exists');
} finally {
    proc_terminate($srv);
}

exec('rm -rf ' . escapeshellarg($tmp));
foreach ($fails as $f) echo "FAIL  $f\n";
printf("%d HTTP checks passed, %d failed\n", $pass, count($fails));
exit($fails ? 1 : 0);

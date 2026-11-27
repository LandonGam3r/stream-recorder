<?php
/**
 * What a recording has besides video (required by record.php and the dashboard):
 * viewer counts, chat emotes, bookmarks, and searching the text of all recordings.
 *
 * Files in a recording folder:
 *   viewers.txt     one line per minute: "<video seconds> <viewers> <unix time>"
 *   emotes.json     {"twitch": {"Kappa": "25", ...}, "extra": {"KEKW": "https://cdn.7tv.app/...", ...}}
 *   bookmarks.json  [{"id": "...", "t": video seconds, "note": "...", "added": unix time}, ...]
 */

/** Emote pictures may only come from these sites (they are shown in the player). */
const EMOTE_HOSTS = ['static-cdn.jtvnw.net', 'cdn.betterttv.net', 'cdn.frankerfacez.com', 'cdn.7tv.app', 'files.kick.com'];

// ------------------------------------------------------------------ viewers

function viewers_append(string $recDir, float $videoSec, int $viewers): void
{
    @file_put_contents("$recDir/viewers.txt", sprintf("%d %d %d\n", (int)round($videoSec), max(0, $viewers), time()), FILE_APPEND | LOCK_EX);
}

/** [[video seconds, viewers], ...] */
function viewers_read(string $recDir): array
{
    $out = [];
    foreach (@file("$recDir/viewers.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        if (preg_match('/^(\d+) (\d+)/', $l, $m)) $out[] = [(int)$m[1], (int)$m[2]];
    }
    return $out;
}

// ------------------------------------------------------------------ emotes

/** The emotes in one Twitch chat message, from its "emotes" tag ("25:0-4,12-16/1902:6-10"): [text => id]. */
function twitch_message_emotes(string $tag, string $msg): array
{
    $out = [];
    if ($tag === '' || $msg === '') return $out;
    foreach (explode('/', $tag) as $part) {
        if (!preg_match('/^([A-Za-z0-9_]+):(\d+)-(\d+)/', $part, $m)) continue;
        if ((int)$m[3] >= mb_strlen($msg, 'UTF-8') || (int)$m[3] < (int)$m[2]) continue;
        $name = mb_substr($msg, (int)$m[2], (int)$m[3] - (int)$m[2] + 1, 'UTF-8');
        if ($name !== '' && mb_strlen($name) <= 60 && !preg_match('/\s/u', $name)) $out[$name] = $m[1];
    }
    return $out;
}

function emotes_load(string $recDir): array
{
    $j = json_decode((string)@file_get_contents("$recDir/emotes.json"), true);
    return ['twitch' => (array)($j['twitch'] ?? []), 'extra' => (array)($j['extra'] ?? [])];
}

function emotes_save(string $recDir, array $e): void
{
    $f = "$recDir/emotes.json";
    file_put_contents("$f.tmp", json_encode(['twitch' => (object)$e['twitch'], 'extra' => (object)$e['extra']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename("$f.tmp", $f);
}

/** Adds Twitch emotes seen in chat; writes only when something is new. */
function emotes_merge(string $recDir, array $twitch): void
{
    if (!$twitch) return;
    $e = emotes_load($recDir);
    $before = count($e['twitch']);
    foreach ($twitch as $name => $id) {
        if (preg_match('/^[A-Za-z0-9_]{1,80}$/', (string)$id) && count($e['twitch']) < 5000) $e['twitch'][(string)$name] = (string)$id;
    }
    if (count($e['twitch']) !== $before) emotes_save($recDir, $e);
}

function emote_url_ok(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '', EMOTE_HOSTS, true)
        && !isset($p['user']) && !isset($p['port']) && strlen($url) < 300;
}

/** A small JSON GET for the emote services. */
function emote_fetch(string $url): ?array
{
    $code = 0;
    $body = http_request($url, null, ['Accept: application/json'], 8, $code);
    if ($body === null || $code !== 200 || strlen($body) > 5000000) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/**
 * The channel's BetterTTV, FrankerFaceZ and 7TV emotes (and their global ones): [name => picture URL].
 * Twitch's own emotes come from the chat itself. Missing services are skipped.
 */
function emotes_third_party(string $twitchUserId): array
{
    $out = [];
    $add = function (string $name, string $url) use (&$out) {
        if ($name !== '' && mb_strlen($name) <= 60 && !preg_match('/\s/u', $name) && emote_url_ok($url) && count($out) < 3000) $out[$name] = $url;
    };
    $id = preg_replace('/\D/', '', $twitchUserId);
    // BetterTTV
    foreach (array_filter([emote_fetch('https://api.betterttv.net/3/cached/emotes/global'),
        $id !== '' ? emote_fetch("https://api.betterttv.net/3/cached/users/twitch/$id") : null]) as $j) {
        $list = isset($j['channelEmotes']) || isset($j['sharedEmotes']) ? array_merge((array)($j['channelEmotes'] ?? []), (array)($j['sharedEmotes'] ?? [])) : $j;
        foreach ($list as $e) {
            if (is_array($e) && isset($e['id'], $e['code']) && preg_match('/^[a-f0-9]{24}$/', (string)$e['id'])) $add((string)$e['code'], "https://cdn.betterttv.net/emote/{$e['id']}/1x");
        }
    }
    // FrankerFaceZ
    foreach (array_filter([emote_fetch('https://api.frankerfacez.com/v1/set/global'),
        $id !== '' ? emote_fetch("https://api.frankerfacez.com/v1/room/id/$id") : null]) as $j) {
        foreach ((array)($j['sets'] ?? []) as $set) {
            foreach ((array)($set['emoticons'] ?? []) as $e) {
                $u = (string)($e['urls']['1'] ?? '');
                if (strpos($u, '//') === 0) $u = "https:$u";
                if (isset($e['name'])) $add((string)$e['name'], $u);
            }
        }
    }
    // 7TV
    $sets = [emote_fetch('https://7tv.io/v3/emote-sets/global')];
    if ($id !== '') { $u = emote_fetch("https://7tv.io/v3/users/twitch/$id"); $sets[] = $u['emote_set'] ?? null; }
    foreach (array_filter($sets) as $set) {
        foreach ((array)($set['emotes'] ?? []) as $e) {
            $host = (string)($e['data']['host']['url'] ?? '');
            if (strpos($host, '//') === 0) $host = "https:$host";
            if (isset($e['name']) && preg_match('#^https://cdn\.7tv\.app/emote/[A-Za-z0-9]+$#', $host)) $add((string)$e['name'], "$host/1x.webp");
        }
    }
    return $out;
}

// ------------------------------------------------------------------ bookmarks

function bookmarks_load(string $recDir): array
{
    $j = json_decode((string)@file_get_contents("$recDir/bookmarks.json"), true);
    return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
}

/** Locked change of a recording's bookmarks; $fn gets the list by reference and may return an error text. */
function bookmarks_update(string $recDir, callable $fn): ?string
{
    $h = fopen("$recDir/bookmarks.json", 'c+');
    if (!$h) return 'Could not open the bookmarks.';
    flock($h, LOCK_EX);
    $list = json_decode((string)stream_get_contents($h), true);
    if (!is_array($list)) $list = [];
    $err = $fn($list);
    if ($err === null) {
        usort($list, function ($a, $b) { return ($a['t'] ?? 0) <=> ($b['t'] ?? 0); });
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    flock($h, LOCK_UN);
    fclose($h);
    return $err;
}

// ------------------------------------------------------------------ chat time -> video time

function local_ms(string $s): ?float
{
    if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\.(\d{1,3}))?/', $s, $m)) return null;
    $t = strtotime($m[1]);
    return $t === false ? null : $t * 1000 + (int)str_pad($m[2] ?? '0', 3, '0');
}

/** Same mapping as the player: chat clock time -> seconds into the video (ad breaks and gaps are not in the video). */
function chat_mapper(array $meta): callable
{
    $tl = [];
    foreach ((array)($meta['timeline'] ?? []) as $p) {
        if (is_array($p) && isset($p[0], $p[1]) && ($ms = local_ms((string)$p[1])) !== null) $tl[] = [(float)$p[0], $ms];
    }
    $start = isset($meta['video_start']) ? local_ms((string)$meta['video_start']) : null;
    return function (float $ms) use ($tl, $start): ?float {
        if (!$tl) return $start !== null ? max(0.0, ($ms - $start) / 1000) : null;
        $i = 0;
        while ($i + 1 < count($tl) && $tl[$i + 1][1] <= $ms) $i++;
        $t = $tl[$i][0] + ($ms - $tl[$i][1]) / 1000;
        if ($i + 1 < count($tl)) $t = min($t, $tl[$i + 1][0]);
        return max(0.0, $t);
    };
}

// ------------------------------------------------------------------ search across all recordings

/**
 * Searches the text of every recording: chat, spoken words (transcript), AI summaries, titles and bookmarks.
 * $opts: channel (''), kinds (['chat','speech','summary','title','bookmark']), field ('text'|'name' for chat), limit.
 * Returns ['hits' => [...], 'scanned' => recordings, 'more' => bool]. Each hit: channel, folder, kind, t (video
 * seconds or null), text, name, before/after (lines around it, chat and speech only).
 */
function search_recordings(string $root, string $q, array $opts = []): array
{
    $q = trim($q);
    $limit = max(1, min(500, (int)($opts['limit'] ?? 200)));
    $kinds = array_flip($opts['kinds'] ?? ['chat', 'speech', 'summary', 'title', 'bookmark']);
    $field = ($opts['field'] ?? 'text') === 'name' ? 'name' : 'text';
    $only = (string)($opts['channel'] ?? '');
    $deadline = microtime(true) + (float)($opts['seconds'] ?? 20);
    $hits = [];
    $scanned = 0;
    $more = false;
    $has = function (string $hay) use ($q): bool { return $q !== '' && mb_stripos($hay, $q, 0, 'UTF-8') !== false; };
    $dirs = [];
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $chanDir) {
        $ch = basename($chanDir);
        if ($only !== '' && $ch !== $only) continue;
        foreach (glob("$chanDir/*", GLOB_ONLYDIR) ?: [] as $d) if (is_file("$d/meta.json")) $dirs[] = $d;
    }
    usort($dirs, function ($a, $b) { return strcmp(basename($b), basename($a)); }); // newest first (folders start with the date)
    foreach ($dirs as $d) {
        if (count($hits) >= $limit || microtime(true) > $deadline) { $more = true; break; }
        $scanned++;
        $ch = basename(dirname($d));
        $meta = json_decode((string)@file_get_contents("$d/meta.json"), true) ?: [];
        $base = ['channel' => $ch, 'folder' => basename($d), 'title' => (string)($meta['title'] ?? ''), 'started' => $meta['stream_started'] ?? null,
            'video' => is_file("$d/video.mp4"), 'text_only' => !empty($meta['footage_deleted'])];
        $push = function (array $h) use (&$hits, $base, $limit, &$more) {
            if (count($hits) >= $limit) { $more = true; return false; }
            $hits[] = $base + $h;
            return true;
        };
        if (isset($kinds['title']) && $field === 'text') {
            $titles = array_unique(array_filter(array_merge([(string)($meta['title'] ?? '')], array_column((array)($meta['changes'] ?? []), 'title'))));
            foreach ($titles as $t) if ($has($t)) { $push(['kind' => 'title', 't' => null, 'text' => $t]); break; }
        }
        if (isset($kinds['summary']) && $field === 'text' && is_file("$d/ai_summary.md")) {
            foreach (preg_split('/\r?\n/', (string)file_get_contents("$d/ai_summary.md")) as $line) {
                if (!$has($line)) continue;
                $t = preg_match('/\[(\d+):(\d\d)(?::(\d\d))?\]/', $line, $m) ? (isset($m[3]) && $m[3] !== '' ? $m[1] * 3600 + $m[2] * 60 + $m[3] : $m[1] * 60 + $m[2]) : null;
                $clean = trim(str_replace('**', '', preg_replace(['/^[#>*\s-]+/', '/^\[\d+:\d\d(?::\d\d)?\]\s*/'], '', $line)));
                if (!$push(['kind' => 'summary', 't' => $t, 'text' => $clean])) break 2;
            }
        }
        if (isset($kinds['bookmark']) && $field === 'text') {
            foreach (bookmarks_load($d) as $b) {
                if ($has((string)($b['note'] ?? '')) && !$push(['kind' => 'bookmark', 't' => (float)($b['t'] ?? 0), 'text' => (string)$b['note']])) break 2;
            }
        }
        if (isset($kinds['speech']) && $field === 'text' && is_file("$d/transcript.json")) {
            $tr = json_decode((string)file_get_contents("$d/transcript.json"), true) ?: [];
            $segs = [];
            $parts = (array)($tr['parts'] ?? []);
            if (!$parts && !empty($tr['segments'])) $parts = [(array)$tr['segments']]; // made by the PC summary tool
            ksort($parts, SORT_NUMERIC);
            foreach ($parts as $p) foreach ((array)$p as $s) if (is_array($s) && isset($s[2])) $segs[] = $s;
            foreach ($segs as $i => $s) {
                $txt = (string)$s[2] . (isset($s[3]) ? ' — ' . $s[3] : '');
                if (!$has($txt)) continue;
                $ctx = function ($j) use ($segs) { return isset($segs[$j]) ? (string)$segs[$j][2] : null; };
                if (!$push(['kind' => 'speech', 't' => (float)$s[0], 'text' => $txt, 'before' => array_values(array_filter([$ctx($i - 1)])), 'after' => array_values(array_filter([$ctx($i + 1)]))])) break 2;
            }
        }
        if (isset($kinds['chat']) && is_file("$d/chat.txt")) {
            $map = chat_mapper($meta);
            $h = fopen("$d/chat.txt", 'r');
            $prev = [];
            $pending = []; // hits still collecting their "after" lines
            while (($line = fgets($h)) !== false) {
                if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d+)?)\] (.*)$/', rtrim($line, "\r\n"), $m)) continue;
                $body = $m[2];
                $name = '';
                if (strpos($body, '** ') === 0) $body = substr($body, 3); // notices (subs, raids)
                if (preg_match('/^([\p{L}\p{N}_]{1,40}): (.*)$/u', $body, $n)) { $name = $n[1]; $text = $n[2]; } else { $text = $body; }
                foreach ($pending as $k => $idx) {
                    $hits[$idx]['after'][] = $body;
                    if (count($hits[$idx]['after']) >= 2) unset($pending[$k]);
                }
                if ($field === 'name' ? ($name !== '' && mb_strtolower($name) === mb_strtolower($q)) : $has($body)) {
                    $ms = local_ms($m[1]);
                    if (!$push(['kind' => 'chat', 't' => $ms !== null ? $map($ms) : null, 'text' => $text, 'name' => $name, 'clock' => substr($m[1], 0, 19), 'before' => $prev, 'after' => []])) break;
                    $pending[] = count($hits) - 1;
                    if (microtime(true) > $deadline) { $more = true; break; }
                }
                $prev[] = $body;
                if (count($prev) > 2) array_shift($prev);
            }
            fclose($h);
        }
    }
    return ['hits' => $hits, 'scanned' => $scanned, 'total' => count($dirs), 'more' => $more];
}

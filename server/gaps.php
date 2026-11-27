<?php
/**
 * Gap repair (required by record.php): fills holes in a Twitch recording from the stream's VOD.
 *
 * A recording has holes where the recorder saw no video: ad breaks (for viewers who get ads, Twitch shows
 * an ad instead of the stream), missed segments and short drops. meta.json's timeline says where each
 * recorded part starts in the video and on the clock, so every hole is known: [clock time it starts,
 * clock time it ends]. Right after the stream, while Twitch's VOD of it exists (most channels keep them),
 * the missing minutes are taken from the VOD and put between the parts as parts/NNN_fill.ts, in the same
 * quality. The timeline, chat sync and viewer counts are moved to match, and only then is the recording
 * converted to MP4. Nothing changes when there is no VOD (switched off, subscribers only, deleted).
 *
 * gaps.json in the recording folder: state pending|working|done|skipped, holes, vod, notes.
 */

const GAPS_MIN_SECONDS   = 4;      // smaller holes are left alone
const GAPS_START_MAX     = 600;    // a missing start (recorder added late) is filled up to 10 minutes
const GAPS_WAIT_FOR_VOD  = 3600;   // look for the VOD this long after the stream, then give up
const GAPS_HOLD_MAX      = 21600;  // never hold the MP4 conversion longer than 6 hours

/**
 * Holes between the recorded parts, from meta.json: [['after' => part index (-1 = before the first),
 * 'from' => unix time, 'to' => unix time, 'at' => video seconds where it goes], ...]
 */
function gaps_find(array $meta, float $length): array
{
    $tl = [];
    foreach ((array)($meta['timeline'] ?? []) as $p) {
        if (!is_array($p) || !isset($p[0], $p[1])) continue;
        $w = local_ms((string)$p[1]);
        if ($w !== null) $tl[] = [(float)$p[0], $w / 1000];
    }
    $holes = [];
    if (!$tl) return $holes;
    $started = strtotime((string)($meta['stream_started'] ?? ''));
    if ($started && $tl[0][1] - $started >= GAPS_MIN_SECONDS && $tl[0][1] - $started <= GAPS_START_MAX) {
        $holes[] = ['after' => -1, 'from' => (float)$started, 'to' => $tl[0][1], 'at' => 0.0];
    }
    for ($i = 0; $i + 1 < count($tl); $i++) {
        $end = $tl[$i][1] + ($tl[$i + 1][0] - $tl[$i][0]); // clock time this part's video ends
        $gap = $tl[$i + 1][1] - $end;
        if ($gap >= GAPS_MIN_SECONDS) $holes[] = ['after' => $i, 'from' => $end, 'to' => $tl[$i + 1][1], 'at' => $tl[$i + 1][0]];
    }
    return $holes;
}

/** Video time after filling: a moment at $t moves by the length of every fill that goes in at or before it. */
function gaps_shift(float $t, array $fills): float
{
    $add = 0.0;
    foreach ($fills as $f) if ($t >= $f['at'] - 0.001) $add += $f['seconds'];
    return $t + $add;
}

/** The new timeline: every part keeps its clock time, each fill gets an entry of its own. */
function gaps_new_timeline(array $timeline, array $fills): array
{
    $out = [];
    $byAfter = [];
    foreach ($fills as $f) $byAfter[$f['after']] = $f;
    if (isset($byAfter[-1])) $out[] = [0, local_time_ms($byAfter[-1]['from'])];
    foreach ($timeline as $i => $p) {
        $out[] = [round(gaps_shift((float)$p[0], $fills), 3), $p[1]];
        if (isset($byAfter[$i]) && $i + 1 < count($timeline)) {
            $f = $byAfter[$i];
            $out[] = [round(gaps_shift((float)$timeline[$i + 1][0], $fills) - $f['seconds'], 3), local_time_ms($f['from'])];
        }
    }
    return $out;
}

/** After a stream: note the holes for repair (Twitch only). Returns true when the conversion should wait. */
function gaps_after_stream(string $dir): bool
{
    if (!GAP_REPAIR || platform_of(basename(dirname($dir))) !== 'twitch') return false;
    $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
    $holes = gaps_find($meta, (float)($meta['recorded_seconds'] ?? 0));
    if (!$holes) return false;
    $parts = video_sources($dir);
    if (count($parts) !== count((array)$meta['timeline']) || basename($parts[0]) === 'video.ts') {
        logmsg('Gap repair: the parts don\'t match the timeline (older recording?) - skipped');
        return false;
    }
    $total = array_sum(array_map(function ($h) { return $h['to'] - $h['from']; }, $holes));
    file_put_contents("$dir/gaps.json", json_encode(['state' => 'pending', 'created' => time(), 'holes' => $holes, 'missing' => round($total)]));
    logmsg(sprintf('Gap repair: %d hole(s), %s missing in total (ad breaks, drops) - filling them from the VOD before the MP4 conversion', count($holes), gmdate('H:i:s', (int)$total)));
    return true;
}

/** Does the MP4 conversion of this recording wait for its gap repair? */
function gaps_hold(string $dir): bool
{
    $g = json_decode((string)@file_get_contents("$dir/gaps.json"), true);
    return is_array($g) && in_array($g['state'] ?? '', ['pending', 'working'], true) && time() - (int)($g['created'] ?? 0) < GAPS_HOLD_MAX;
}

function gaps_save(string $dir, array $g): void
{
    file_put_contents("$dir/gaps.json.tmp", json_encode($g, JSON_UNESCAPED_SLASHES));
    rename("$dir/gaps.json.tmp", "$dir/gaps.json");
}

/** In the manager: works on one recording's holes for a while; continues next run. */
function process_gap_repairs(): void
{
    foreach (glob(RECORDINGS_DIR . '/*/*/gaps.json') ?: [] as $f) {
        $dir = dirname($f);
        $g = json_decode((string)file_get_contents($f), true) ?: [];
        if (!in_array($g['state'] ?? '', ['pending', 'working'], true)) continue;
        $own = $GLOBALS['logFile'];
        $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
        try { gaps_work($dir, $g, microtime(true) + 40); } finally { $GLOBALS['logFile'] = $own; }
        return; // one recording per run
    }
}

function gaps_work(string $dir, array $g, float $deadline): void
{
    $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
    $login = platform_parse(basename(dirname($dir)))['name'] ?? '';
    $ff = find_ffmpeg();
    $end = function (string $state, string $note) use ($dir, &$g) {
        $g['state'] = $state; $g['note'] = $note; $g['finished'] = time();
        gaps_save($dir, $g);
        logmsg("Gap repair: $note");
    };
    if (!$ff) { $end('skipped', 'needs ffmpeg'); return; }
    if (time() - (int)$g['created'] > GAPS_HOLD_MAX) { $end('skipped', 'took too long - converting without it'); return; }

    // 1. The VOD of this stream (its "created" time is the stream's start).
    if (empty($g['vod'])) {
        if (time() - (int)($g['vod_tried'] ?? 0) < 300 && !empty($g['vod_tried'])) return;
        $g['vod_tried'] = time();
        $vod = gaps_find_vod($login, (int)strtotime((string)($meta['stream_started'] ?? '')));
        if (!$vod) {
            if (time() - (int)$g['created'] > GAPS_WAIT_FOR_VOD) { $end('skipped', 'no VOD of this stream on Twitch (VODs switched off, subscribers only or deleted) - the holes stay'); return; }
            $g['state'] = 'working'; $g['note'] = 'waiting for the VOD on Twitch';
            gaps_save($dir, $g);
            return;
        }
        $g['vod'] = $vod;
        $g['state'] = 'working';
        logmsg("Gap repair: using VOD {$vod['id']} (started " . date('H:i:s', $vod['start']) . ')');
    }
    // 2. Its playlist, in the quality that was recorded.
    $segs = gaps_vod_segments((string)$g['vod']['id'], (string)($meta['quality'] ?? ''));
    if ($segs === null) { $end('skipped', 'could not open the VOD (subscribers only?) - the holes stay'); return; }
    $vodLen = $segs ? end($segs)['to'] : 0;
    // 3. Fill the holes one by one.
    @mkdir("$dir/gapwork", 0755, true);
    foreach ($g['holes'] as $k => &$h) {
        if (isset($h['done'])) continue;
        if (microtime(true) > $deadline) { gaps_save($dir, $g); return; }
        $a = $h['from'] - $g['vod']['start'];
        $b = $h['to'] - $g['vod']['start'];
        if ($a < 0 || $b > $vodLen + 1 || $b <= $a) { $h['done'] = 'not in the VOD'; continue; }
        $need = array_values(array_filter($segs, function ($s) use ($a, $b) { return $s['to'] > $a && $s['from'] < $b; }));
        $tmp = "$dir/gapwork/hole$k.ts";
        $out = fopen($tmp, 'wb');
        $ok = $need && $out;
        foreach ($need as $s) {
            $code = 0;
            $data = $ok ? http_get($s['url'], 30, $code) : null;
            if ($data === null || $code !== 200) { $ok = false; break; }
            fwrite($out, $data);
        }
        if ($out) fclose($out);
        if (!$ok) { @unlink($tmp); $h['tries'] = ($h['tries'] ?? 0) + 1; if ($h['tries'] >= 3) $h['done'] = 'download failed'; gaps_save($dir, $g); return; }
        // Cut out exactly the missing part (at the nearest keyframe: Twitch has one every 2 seconds).
        // Named after the part before the hole, so it sorts right after it (001.ts, 001_fill.ts, 002.ts).
        $name = $h['after'] < 0 ? '000_fill.ts' : sprintf('%03d_fill.ts', $h['after'] + gaps_first_part($dir));
        $dest = "$dir/parts/$name";
        $rel = max(0, $a - $need[0]['from']);
        @exec(escapeshellarg($ff) . ' -nostdin -hide_banner -loglevel error -y -ss ' . sprintf('%.3f', $rel) . ' -i ' . escapeshellarg($tmp)
            . ' -t ' . sprintf('%.3f', $b - $a) . ' -map 0:v? -map 0:a? -c copy -f mpegts ' . escapeshellarg("$dest.tmp") . ' 2>&1', $o, $rc);
        @unlink($tmp);
        if ($rc !== 0 || !is_file("$dest.tmp") || filesize("$dest.tmp") < 1000) { @unlink("$dest.tmp"); $h['done'] = 'cutting failed'; continue; }
        rename("$dest.tmp", $dest);
        $h['done'] = 'filled';
        $h['file'] = $name;
        $h['seconds'] = round(media_duration($ff, $dest), 3);
        gaps_save($dir, $g);
    }
    unset($h);
    @rmdir("$dir/gapwork");
    // 4. All holes handled: move the timeline and everything that uses video times.
    $fills = array_values(array_filter($g['holes'], function ($h) { return ($h['done'] ?? '') === 'filled' && !empty($h['seconds']); }));
    if ($fills) gaps_apply($dir, $meta, $fills);
    $added = array_sum(array_column($fills, 'seconds'));
    $end('done', sprintf('%d of %d hole(s) filled from the VOD, %s added', count($fills), count($g['holes']), gmdate('H:i:s', (int)round($added))));
    $g['added'] = round($added);
    gaps_save($dir, $g);
}

/** The number of the first part (normally 1). */
function gaps_first_part(string $dir): int
{
    $p = glob("$dir/parts/[0-9][0-9][0-9].ts") ?: [];
    sort($p);
    return $p ? (int)basename($p[0], '.ts') : 1;
}

/** Changes meta.json, chat.txt's sync line and viewers.txt to the filled video. */
function gaps_apply(string $dir, array $meta, array $fills): void
{
    $meta['timeline'] = gaps_new_timeline((array)$meta['timeline'], $fills);
    $add = array_sum(array_column($fills, 'seconds'));
    $meta['recorded_seconds'] = round((float)($meta['recorded_seconds'] ?? 0) + $add);
    foreach ($meta['changes'] ?? [] as $i => $c) $meta['changes'][$i]['video'] = round(gaps_shift((float)($c['video'] ?? 0), $fills));
    $meta['gaps_filled'] = ['count' => count($fills), 'seconds' => round($add)];
    file_put_contents("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    embed_meta_in_chat($dir);
    if (is_file("$dir/viewers.txt")) {
        $lines = [];
        foreach (file("$dir/viewers.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            if (preg_match('/^(\d+) (\d+)(.*)$/', $l, $m)) $lines[] = round(gaps_shift((float)$m[1], $fills)) . " {$m[2]}{$m[3]}";
        }
        file_put_contents("$dir/viewers.txt", implode("\n", $lines) . "\n");
    }
    foreach (['checkins.json', 'bookmarks.json'] as $jf) {
        if (!is_file("$dir/$jf")) continue;
        $l = json_decode((string)file_get_contents("$dir/$jf"), true) ?: [];
        foreach ($l as $i => $x) if (isset($x['t'])) $l[$i]['t'] = round(gaps_shift((float)$x['t'], $fills), 1);
        file_put_contents("$dir/$jf", json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (function_exists('apply_moments')) apply_moments($dir); // moments again, at the new times
}

/** Twitch's VOD of the stream that started at $started: ['id', 'start' => unix time] or null. */
function gaps_find_vod(string $login, int $started): ?array
{
    if ($login === '' || !$started) return null;
    $d = gql('query($login: String!) { user(login: $login) { videos(first: 10, type: ARCHIVE, sort: TIME) { edges { node { id createdAt lengthSeconds status } } } } }',
        ['login' => $login], use_twitch_auth());
    foreach ($d['user']['videos']['edges'] ?? [] as $e) {
        $n = $e['node'] ?? [];
        $t = strtotime((string)($n['createdAt'] ?? ''));
        if ($t && abs($t - $started) <= 600 && preg_match('/^\d+$/', (string)($n['id'] ?? ''))) return ['id' => (string)$n['id'], 'start' => $t, 'length' => (int)($n['lengthSeconds'] ?? 0)];
    }
    return null;
}

/** The VOD's segments in the recorded quality: [['url', 'from', 'to'], ...] (seconds into the VOD), or null. */
function gaps_vod_segments(string $id, string $quality): ?array
{
    $tok = gql('query($id: ID!) { videoPlaybackAccessToken(id: $id, params: {platform: "web", playerBackend: "mediaplayer", playerType: "site"}) { value signature } }',
        ['id' => $id], use_twitch_auth())['videoPlaybackAccessToken'] ?? null;
    if (!$tok) return null;
    $q = http_build_query(['sig' => $tok['signature'], 'token' => $tok['value'], 'allow_source' => 'true', 'allow_audio_only' => 'true',
        'player' => 'twitchweb', 'playlist_include_framerate' => 'true', 'p' => random_int(100000, 999999)]);
    $code = 0;
    $master = http_get(twitch_url("https://usher.ttvnw.net/vod/$id.m3u8?$q"), 15, $code);
    if ($master === null || $code !== 200) return null;
    $url = gaps_pick($master, $quality);
    if (!$url) return null;
    $pl = http_get($url, 15, $code);
    if ($pl === null || $code !== 200) return null;
    $base = substr($url, 0, (int)strrpos(strtok($url, '?'), '/') + 1);
    $out = [];
    $t = 0.0;
    $dur = 0.0;
    foreach (preg_split('/\r?\n/', $pl) as $l) {
        $l = trim($l);
        if (strpos($l, '#EXTINF:') === 0) $dur = (float)substr($l, 8);
        elseif ($l !== '' && $l[0] !== '#') {
            $out[] = ['url' => preg_match('#^https?://#', $l) ? $l : $base . $l, 'from' => $t, 'to' => $t + $dur];
            $t += $dur;
        }
    }
    return $out;
}

/** The VOD rendition that matches what was recorded ("480p30", "1080p60 (source)" ...), else the best. */
function gaps_pick(string $master, string $quality): ?string
{
    $want = strtolower(trim(preg_replace('/\s*\(.*$/', '', $quality)));
    $source = stripos($quality, 'source') !== false;
    $lines = preg_split('/\r?\n/', trim($master));
    $names = [];
    $best = null;
    foreach ($lines as $i => $l) {
        if (strpos($l, '#EXT-X-MEDIA:') === 0 && preg_match('/GROUP-ID="([^"]+)"/', $l, $g) && preg_match('/NAME="([^"]+)"/', $l, $n)) $names[$g[1]] = $n[1];
        if (strpos($l, '#EXT-X-STREAM-INF:') !== 0) continue;
        $url = trim($lines[$i + 1] ?? '');
        $group = preg_match('/VIDEO="([^"]+)"/', $l, $v) ? $v[1] : '';
        $name = strtolower(preg_replace('/\s*\(.*$/', '', $names[$group] ?? $group));
        $bw = preg_match('/BANDWIDTH=(\d+)/', $l, $b) ? (int)$b[1] : 0;
        if (($source && $group === 'chunked') || ($want !== '' && $name === $want)) return $url;
        if ($group !== 'audio_only' && (!$best || $bw > $best[0])) $best = [$bw, $url];
    }
    return $best[1] ?? null;
}

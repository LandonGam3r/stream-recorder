<?php
/**
 * PC scan: subtitles, translation and spoken-word moments, made on your own PC.
 *
 * The server's part is small: it cuts the recording's sound into pieces of 10 minutes (mono, 16 kHz,
 * about 2 MB each), the dashboard's scan page downloads those to your PC, recognises the speech there
 * (in the browser, on your graphics card), translates it and sends the text back.
 *
 * Per recording, scan.json holds the state:
 *   ask        - the stream ended; the dashboard asks whether to scan (streamer setting "PC scan: ask")
 *   requested  - you chose Scan; the server prepares the sound next run
 *   preparing  - ffmpeg is cutting the sound
 *   ready      - sound pieces ready; the scan page can start
 *   scanning   - the scan page is busy (it saves after every piece, so it can resume)
 *   done       - transcript.json and subtitles*.vtt are in the folder
 *   skipped / failed / cancelled
 * While a scan is asked or running, the YouTube upload of that recording waits (at most
 * SCAN_WAIT_HOURS without any progress), so the upload can use the spoken-word moments.
 *
 * Loaded by record.php (needs its logmsg(), find_ffmpeg(), exec_available(), video_sources()) and by
 * the dashboard (only the scan_* helpers that don't start anything).
 */

const SCAN_HOLD_STATES = ['ask', 'requested', 'preparing', 'ready', 'scanning'];
const SCAN_PIECE_SECONDS = 600;

function scan_load(string $dir): array
{
    $j = json_decode((string)@file_get_contents("$dir/scan.json"), true);
    return is_array($j) ? $j : [];
}

/** Locked read-modify-write of scan.json. $fn gets the array by reference. */
function scan_update(string $dir, callable $fn): array
{
    $h = @fopen("$dir/scan.json", 'c+');
    if (!$h) return [];
    flock($h, LOCK_EX);
    $s = json_decode((string)stream_get_contents($h), true) ?: [];
    $fn($s);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($h, LOCK_UN);
    fclose($h);
    return $s;
}

/** Sound pieces listed by ffmpeg (audio/list.csv): [[file, start, end], ...] or []. */
function scan_audio_parts(string $dir): array
{
    $parts = [];
    foreach (@file("$dir/audio/list.csv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $c = str_getcsv($line);
        if (count($c) >= 3 && preg_match('/^part\d{3}\.(ogg|m4a)$/', $c[0]) && is_file("$dir/audio/{$c[0]}")) {
            $parts[] = [$c[0], round((float)$c[1], 3), round((float)$c[2], 3)];
        }
    }
    return $parts;
}

/** How far ffmpeg got preparing the sound: ['done' => seconds, 'total' => seconds, 'speed' => max x real time] or null. */
function scan_prep_progress(string $dir, array $s): ?array
{
    if (($s['state'] ?? '') !== 'preparing') return null;
    $f = "$dir/audio/progress.txt";
    $tail = (string)@file_get_contents($f, false, null, max(0, (int)@filesize($f) - 2048));
    $done = 0;
    if (preg_match_all('/out_time_(?:us|ms)=(\d+)/', $tail, $m)) $done = (int)end($m[1]) / 1e6;
    return ['done' => round($done), 'total' => (float)($s['prep_total'] ?? 0), 'speed' => (float)($s['prep_speed'] ?? 0),
        'started' => (int)($s['job_started'] ?? 0)];
}

/** Should the YouTube upload of this recording wait for its scan? (logs once when the wait runs out) */
function scan_holds_upload(string $dir): bool
{
    if (!is_file("$dir/scan.json")) return false;
    $s = scan_load($dir);
    if (!in_array($s['state'] ?? '', SCAN_HOLD_STATES, true)) return false;
    $since = (int)($s['updated'] ?? $s['asked'] ?? 0);
    if (time() - $since < SCAN_WAIT_HOURS * 3600) return true;
    if (empty($s['upload_released'])) {
        scan_update($dir, function (array &$x) {
            if (($x['state'] ?? '') === 'ask') { $x['state'] = 'skipped'; $x['note'] = 'no answer in time'; }
            $x['upload_released'] = time();
        });
        if (function_exists('logmsg')) logmsg('PC scan of ' . basename($dir) . ' did not finish within ' . SCAN_WAIT_HOURS
            . ' hours - uploading to YouTube without it');
    }
    return false;
}

/** Called when a stream ends: ask about the scan when the streamer's setting says so. */
function scan_after_stream(string $dir, ?array $channelSettings): bool
{
    if (($channelSettings['pc_scan'] ?? 'off') !== 'ask') return false;
    scan_update($dir, function (array &$s) {
        $s = ['state' => 'ask', 'asked' => time(), 'updated' => time()];
    });
    return true;
}

/** Turns subtitle segments [[start, end, text, translation?], ...] into WebVTT. $col 2 = original, 3 = translation. */
function scan_vtt(array $segments, int $col): string
{
    $ts = function (float $t) {
        $ms = (int)round(max(0, $t) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    };
    $out = "WEBVTT\n\n";
    $n = 0;
    foreach ($segments as $seg) {
        $text = trim(str_replace(["\r", '-->'], ['', '->'], (string)($seg[$col] ?? '')));
        if ($text === '') continue;
        $s = (float)$seg[0];
        $e = max((float)$seg[1], $s + 0.5);
        $out .= ++$n . "\n" . $ts($s) . ' --> ' . $ts($e) . "\n" . preg_replace("/\n{2,}/", "\n", $text) . "\n\n";
    }
    return $out;
}

// ------------------------------------------------------------------ server work (record.php only)

/** Prepares the sound for requested scans and finishes ffmpeg jobs; one at a time. Runs in the manager. */
function process_scans(): void
{
    if (!function_exists('find_ffmpeg')) return;
    $files = glob(RECORDINGS_DIR . '/*/*/scan.json') ?: [];
    if (!$files) return;
    $convertQ = json_decode((string)@file_get_contents(RECORDINGS_DIR . '/.convert_queue.json'), true) ?: [];
    $busy = false;
    $waiting = [];
    foreach ($files as $f) {
        $dir = dirname($f);
        $s = scan_load($dir);
        $state = $s['state'] ?? '';
        if ($state === 'preparing') {
            $busy = true;
            scan_check_audio_job($dir, $s);
        } elseif ($state === 'requested') {
            $waiting[] = $dir;
        } elseif ($state === 'done' && !empty($s['moments_pending'])) {
            $own = $GLOBALS['logFile'] ?? null;
            $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
            $mo = function_exists('apply_moments') ? apply_moments($dir) : null;
            logmsg('PC scan saved for ' . basename($dir) . ': ' . (int)($s['segments'] ?? 0) . ' subtitle lines'
                . ($mo ? ', moments now: ' . count($mo['moments']) . ($mo['labels'] ? ' (' . implode(', ', $mo['labels']) . ')' : '') : ''));
            $GLOBALS['logFile'] = $own;
            scan_update($dir, function (array &$x) { unset($x['moments_pending']); });
        }
    }
    if ($busy || !$waiting) return;
    $note = function (string $n) use ($waiting) {
        foreach ($waiting as $d) scan_update($d, function (array &$x) use ($n) { $x['note'] = $n; });
    };
    foreach ($convertQ as $j) if (!empty($j['started'])) { $note('waits for the running MP4 conversion to finish'); return; }
    // Only the sound is read, so it may run during a recording - at half speed, to leave the IO limit to the recording.
    $recording = function_exists('any_channel_recording') && any_channel_recording();
    $speed = CONVERT_SPEED > 0 ? 2 * (float)CONVERT_SPEED : 0;
    if ($recording && CONVERT_WHEN_IDLE) {
        if (CONVERT_SPEED <= 0) { $note('waits until nothing is recording (set CONVERT_SPEED to allow it during recordings)'); return; }
        $speed = (float)CONVERT_SPEED;
    }
    foreach ($waiting as $dir) {
        if (isset($convertQ[$dir])) {                                       // needs the finished video first
            scan_update($dir, function (array &$x) { $x['note'] = 'waiting for the MP4 conversion'; });
            continue;
        }
        $own = $GLOBALS['logFile'] ?? null;
        $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
        scan_start_audio_job($dir, $speed);
        $GLOBALS['logFile'] = $own;
        return; // one at a time
    }
}

function scan_start_audio_job(string $dir, float $maxSpeed): void
{
    // Already cut earlier (a second scan, e.g. with another language): reuse.
    if (scan_audio_parts($dir) && trim((string)@file_get_contents("$dir/audio/.exit")) === '0') {
        scan_update($dir, function (array &$x) { $x['state'] = 'ready'; $x['updated'] = time(); unset($x['note']); });
        return;
    }
    $ff = find_ffmpeg();
    $fail = function (string $why) use ($dir) {
        logmsg('PC scan: cannot prepare the sound of ' . basename($dir) . ": $why");
        scan_update($dir, function (array &$x) use ($why) { $x['state'] = 'failed'; $x['error'] = $why; $x['updated'] = time(); });
    };
    if (!$ff || !exec_available()) { $fail($GLOBALS['ffmpegProblem'] ?? 'no ffmpeg'); return; }

    $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
    $useTs = !empty($meta['mp4_incomplete']) && is_file("$dir/video.ts");
    if (!$useTs && is_file("$dir/video.mp4")) $input = ' -i ' . escapeshellarg("$dir/video.mp4");
    else {
        $src = video_sources($dir);
        if (!$src) { $fail('no video file'); return; }
        if (count($src) === 1) $input = ' -i ' . escapeshellarg($src[0]);
        else {
            file_put_contents("$dir/.scan_concat.txt", implode("\n", array_map(function ($f) {
                return "file '" . str_replace("'", "'\\''", $f) . "'";
            }, $src)) . "\n");
            $input = ' -f concat -safe 0 -i ' . escapeshellarg("$dir/.scan_concat.txt");
        }
    }
    $a = "$dir/audio";
    if (is_dir($a)) foreach (glob("$a/{*,.*}", GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    @mkdir($a, 0755, true);

    // Opus is smaller and clearer at this low bitrate; AAC is in every ffmpeg.
    $out = [];
    @exec(escapeshellarg($ff) . ' -hide_banner -encoders 2>/dev/null | grep -c libopus', $out);
    $opus = (int)($out[0] ?? 0) > 0;
    $codec = $opus ? ' -c:a libopus -b:a 20k -application voip -segment_format ogg ' . escapeshellarg("$a/part%03d.ogg")
                   : ' -c:a aac -b:a 32k -segment_format mp4 ' . escapeshellarg("$a/part%03d.m4a");
    // Only the sound is decoded, so it's light on CPU; reading the file is what counts towards the IO limit.
    $speed = $maxSpeed > 0 && ffmpeg_has_readrate($ff) ? $maxSpeed : 0;
    $prefix = (@is_executable('/usr/bin/ionice') ? '/usr/bin/ionice -c3 ' : '')
        . (@is_executable('/usr/bin/nice') ? '/usr/bin/nice -n 19 ' : (@is_executable('/bin/nice') ? '/bin/nice -n 19 ' : ''));
    $total = (float)($meta['recorded_seconds'] ?? 0);
    if ($total <= 0 && strpos($input, ' -f concat') === false) $total = media_duration($ff, trim(substr($input, 4), "' "));
    $ffcmd = $prefix . escapeshellarg($ff) . ' -hide_banner -loglevel error -nostats -y -progress ' . escapeshellarg("$a/progress.txt")
        . ($speed ? " -readrate $speed" : '')
        . $input . ' -map 0:a:0 -vn -ac 1 -ar 16000 -f segment -segment_time ' . SCAN_PIECE_SECONDS
        . ' -reset_timestamps 1 -segment_list ' . escapeshellarg("$a/list.csv") . ' -segment_list_type csv' . $codec
        . ' 2> ' . escapeshellarg("$a/ffmpeg.log") . ' < /dev/null';
    $inner = 'echo $$ > ' . escapeshellarg("$a/.pid") . '; ' . $ffcmd
        . '; echo $? > ' . escapeshellarg("$a/.exit.tmp") . ' && mv ' . escapeshellarg("$a/.exit.tmp") . ' ' . escapeshellarg("$a/.exit");
    $setsid = @is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (@is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    @exec('nohup ' . $setsid . '/bin/sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &');
    scan_update($dir, function (array &$x) use ($total, $speed) {
        $x['state'] = 'preparing'; $x['job_started'] = time(); $x['updated'] = time();
        $x['prep_total'] = round($total); $x['prep_speed'] = $speed;
        $x['job_tries'] = (int)($x['job_tries'] ?? 0) + 1; unset($x['note'], $x['error']);
    });
    logmsg('PC scan: preparing the sound of ' . basename($dir) . ' (' . ($opus ? 'Opus' : 'AAC') . ($speed ? ", max {$speed}x speed" : '') . ')');
}

function scan_check_audio_job(string $dir, array $s): void
{
    $a = "$dir/audio";
    $own = $GLOBALS['logFile'] ?? null;
    $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
    if (is_file("$a/.exit")) {
        $code = trim((string)file_get_contents("$a/.exit"));
        $parts = scan_audio_parts($dir);
        if ($code === '0' && $parts) {
            $secs = end($parts)[2];
            scan_update($dir, function (array &$x) use ($parts, $secs) {
                $x['state'] = 'ready'; $x['updated'] = time(); $x['pieces'] = count($parts); $x['seconds'] = $secs;
            });
            logmsg(sprintf('PC scan: sound ready for %s (%d pieces, %s) - the scan can start on your PC', basename($dir), count($parts), gmdate('H:i:s', (int)$secs)));
        } else {
            $err = trim((string)@file_get_contents("$a/ffmpeg.log", false, null, max(0, (int)@filesize("$a/ffmpeg.log") - 400)));
            $why = $parts ? "ffmpeg ended with code $code" : 'the recording has no sound track';
            if ($err !== '') $why .= ': ' . preg_replace('/\s+/', ' ', $err);
            scan_update($dir, function (array &$x) use ($why) { $x['state'] = 'failed'; $x['error'] = $why; $x['updated'] = time(); });
            logmsg('PC scan: preparing the sound failed for ' . basename($dir) . ": $why");
        }
    } else {
        // No exit code: still running, or stopped by the host.
        $pid = (int)@file_get_contents("$a/.pid");
        $alive = $pid > 0 && (function_exists('posix_kill') ? @posix_kill($pid, 0) : @file_exists("/proc/$pid"));
        $last = max((int)($s['job_started'] ?? 0), ...array_map('filemtime', glob("$a/part*") ?: [$a]));
        if (!$alive && time() - $last > 180) {
            if ((int)($s['job_tries'] ?? 0) >= 3) {
                scan_update($dir, function (array &$x) { $x['state'] = 'failed'; $x['error'] = 'ffmpeg was stopped 3 times before it finished'; $x['updated'] = time(); });
                logmsg('PC scan: gave up preparing the sound of ' . basename($dir) . ' (ffmpeg stopped 3 times)');
            } else {
                scan_update($dir, function (array &$x) { $x['state'] = 'requested'; $x['note'] = 'ffmpeg stopped - trying again'; });
                logmsg('PC scan: ffmpeg stopped before it finished - will try again');
            }
        }
    }
    $GLOBALS['logFile'] = $own;
}

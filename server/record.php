<?php
// Never run from a web request (and write nothing first): this is for cron / the command line only.
if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Run this from cron / the command line.\n");
}
/**
 * Twitch live recorder - cron version (relay mode)
 *
 * One cron line, every minute (or start-local.sh, which does the same). REC_KEY: see docs/twitch-token.md:
 *
 *   * * * * * REC_KEY=... /usr/bin/php -q /path/to/record.php >> /path/to/cron_output.txt 2>&1
 *
 * Without a streamer name this is the manager: it reads channels.json (edited from the dashboard:
 * add streamers, switch recording on/off, YouTube account) and starts a recording run for every
 * switched-on streamer, plus conversions, uploads and the IO graph sampler.
 * "record.php CHANNEL" is one recording run for one streamer (the manager starts these; old
 * per-streamer cron lines still work and put the streamer on the dashboard's list).
 *
 * Relay: while one run records, the next run prepares (live check, playlist, chat connection)
 * and waits. The running one then stops and the waiting one takes over within ~2 seconds, so no
 * video is lost between runs. Chat lines seen by both runs are de-duplicated.
 *
 * Logs: recordings/CHANNEL/recorder.log, and recordings/.system.log for the manager.
 */

// ============================ SETTINGS ============================
// Your settings go in config.php next to this file (copy config.example.php - every setting is
// explained there). Anything config.php doesn't set uses the default below.
const RECORDINGS_DIR = __DIR__ . '/recordings';
const SECRET_DIR     = __DIR__ . '/secret';
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
foreach ([
    'QUALITY'                 => 'best',
    'RECORD_CHAT'             => true,
    'TIMEZONE'                => '',      // '' = the server's own time zone
    'MIN_RUNTIME'             => 50,
    'MAX_RUNTIME'             => 300,
    'POLL_INTERVAL'           => 2,
    'OFFLINE_CHECK_INTERVAL'  => 10,
    'TOKEN_CHECK_INTERVAL'    => 3600,
    'AUTO_CONVERT'            => true,
    'FFMPEG_PATH'             => '',
    'DELETE_TS_AFTER_CONVERT' => true,
    'CONVERT_WHEN_IDLE'       => true,
    'CONVERT_SPEED'           => 0,
    'CONVERT_ATTEMPTS'        => 3,
    'CONVERT_FRAGMENTED'      => true,
    'YOUTUBE_PRIVACY'         => 'private',
    'YOUTUBE_CATEGORY'        => '20',
    'UPLOAD_BUDGET'           => 45,
    'UPLOAD_CHUNK_MB'         => 16,
    'SCAN_WAIT_HOURS'         => 24,
    'MIN_FREE_GB'             => 5,
    'MAX_RECORDING_HOURS'     => 0,
    'LOG_MAX_MB'              => 5,
    'MOMENTS_QUOTE_CHAT'      => true,
    'DEBUG'                   => false,
] as $k => $v) {
    if (!defined($k)) define($k, $v);
}
// ==================================================================

const GQL_CLIENT_ID = 'kimne78kx3ncx6brgo4mv6wki5h1ko'; // Twitch web player's public client ID
const USER_AGENT    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const CHAT_HOST     = 'irc-ws.chat.twitch.tv'; // Twitch chat over WebSocket, port 443

if (TIMEZONE !== '') date_default_timezone_set(TIMEZONE);

// Proof of life: written before anything else can fail (not by the automated tests).
if (!defined('RECORDER_TESTING')) @file_put_contents(
    __DIR__ . '/last_run.txt',
    date('Y-m-d H:i:s') . ' | PHP ' . PHP_VERSION . ' | SAPI ' . PHP_SAPI
    . ' | args: ' . json_encode($argv ?? ($_SERVER['argv'] ?? null))
    . ' | max_execution_time ' . ini_get('max_execution_time')
    . ' | curl ' . (function_exists('curl_init') ? 'yes' : 'NO') . "\n",
    FILE_APPEND
);
// Keep last_run.txt small.
if (@filesize(__DIR__ . '/last_run.txt') > 100000) {
    @file_put_contents(__DIR__ . '/last_run.txt', '');
}

if (!function_exists('curl_init')) {
    exit("PHP cURL extension is required.\n");
}

const CHANNELS_FILE = __DIR__ . '/channels.json'; // the streamer list, edited from the dashboard
const MOMENTS_FILE  = __DIR__ . '/moments.json';  // YouTube "moments" rules, edited from the dashboard
require_once __DIR__ . '/platforms.php'; // Twitch, Kick, YouTube Live: streamer keys and live checks
require_once __DIR__ . '/archive.php';   // text archive (delete footage, keep text) and storage rules
if (is_file(__DIR__ . '/moments.php')) require_once __DIR__ . '/moments.php';
if (is_file(__DIR__ . '/scan.php')) require_once __DIR__ . '/scan.php';     // PC scan (subtitles, translation, spoken moments)
if (is_file(__DIR__ . '/summary.php')) require_once __DIR__ . '/summary.php'; // AI summary of a stream
const IO_SAMPLE_INTERVAL = 5; // seconds between IO measurements for the dashboard graph

$argvList = $argv ?? ($_SERVER['argv'] ?? []);
$arg1     = (string)($argvList[1] ?? '');

// The automated tests (tests/) load this file for its functions only.
if (defined('RECORDER_TESTING')) return;

@set_time_limit(0);
ignore_user_abort(true);

// ---------------------------------------------------------------- setup
@mkdir(RECORDINGS_DIR, 0755, true);
if (!file_exists(RECORDINGS_DIR . '/.htaccess')) {
    file_put_contents(RECORDINGS_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}
if ($arg1 === '--io-sampler') {
    io_sampler_main();
    exit(0);
}
if ($arg1 === '') {
    run_manager();
    exit(0);
}
$P = platform_parse($arg1); // "name" = Twitch, "kick-name", "youtube-handle"
if (!$P) exit("Not a streamer name: $arg1\n");
$channel = $P['key'];
$GLOBALS['channel']  = $channel;
$GLOBALS['platform'] = $P;
$chanDir = RECORDINGS_DIR . '/' . $channel;
@mkdir($chanDir, 0755, true);
$GLOBALS['logFile']   = $chanDir . '/recorder.log';
$GLOBALS['stateFile'] = $chanDir . '/state.json';
$handoverFile         = $chanDir . '/.handover';

// If PHP itself crashes (e.g. out of memory), say so in the channel log instead of dying silently.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        @logmsg('PHP CRASHED: ' . $e['message'] . ' (line ' . $e['line'] . ')');
    }
});

stop_conversions_check();
process_secret_setups(); // encrypt newly dropped-in YouTube account files
check_token_health();    // throttled; shared by all channels

// Started by an old per-streamer cron line? Then put the streamer on the dashboard's list.
if (channel_settings($channel) === null) {
    channels_update(function (array &$list) use ($channel) {
        $list[$channel] = $list[$channel] ?? ['enabled' => true, 'youtube' => youtube_account_env() ?? '',
            'twitch_auth' => twitch_auth_env(), 'added' => time()];
    });
    logmsg('Added to the dashboard\'s streamer list (from a cron line) - switched on');
}

$lock        = fopen($chanDir . '/.lock', 'c');
// Switched off on the dashboard and another run is recording: that one stops by itself.
if (!channel_enabled($channel) && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$isSuccessor = false;
$info        = null;
$playlistUrl = null;
$qualityName = '';
$chat        = null;
$chatBuffer  = [];

// ---------------------------------------------------------------- relay
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another run is recording right now.
    if (MAX_RUNTIME === 0) {
        if (DEBUG) logmsg('Run skipped - previous run is still recording (single-process mode)');
        exit(0);
    }
    $waitLock = fopen($chanDir . '/.wait.lock', 'c');
    if (!$waitLock || !flock($waitLock, LOCK_EX | LOCK_NB)) {
        if (DEBUG) logmsg('Run skipped - a successor is already waiting');
        exit(0);
    }

    // Prepare everything before taking over, so the handover is near-instant.
    $info = get_stream_info($channel);
    if (!$info || !$info['exists'] || !$info['stream']) {
        exit(0); // offline / API error: the running one will notice and stop by itself
    }
    $playlistUrl = get_media_playlist_url($channel, $qualityName);
    if (!$playlistUrl) {
        exit(0);
    }
    if (RECORD_CHAT && $P['platform'] === 'twitch') {
        $chat = chat_connect($channel);
    }

    touch($handoverFile); // tell the running one we're ready
    $waitStart = microtime(true);
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) - $waitStart > MAX_RUNTIME + 30) {
            logmsg('Gave up waiting for the previous run to hand over');
            @unlink($handoverFile);
            exit(0);
        }
        if ($chat) {
            chat_collect($chat, $chatBuffer, 0.25); // keep chat while waiting
        } else {
            usleep(250000);
        }
    }
    $isSuccessor = true;
    @unlink($handoverFile);
    flock($waitLock, LOCK_UN);
    fclose($waitLock);
    if (DEBUG) logmsg(sprintf('Took over recording (waited %.0fs)', microtime(true) - $waitStart));
} else {
    @unlink($handoverFile); // stale flag from an earlier crash
}

$runStart = microtime(true);
$state    = load_state();

// Switched off on the dashboard: wrap up a recording that is still open, then stop.
if (!channel_enabled($channel)) {
    if (!empty($state['stream_id'])) {
        logmsg('Recording switched off on the dashboard - wrapping up the recording');
        finalize($state, display_name($state, $chanDir, $channel), $chanDir);
        save_state(['run_active' => false]);
    }
    flock($lock, LOCK_UN);
    exit(0);
}

// Did the previous run die without finishing? Then the host killed it.
$prevKilledMsg = null;
if (!empty($state['run_active'])) {
    $lived = (int)round(($state['heartbeat'] ?? 0) - ($state['run_started'] ?? 0));
    $prevKilledMsg = "WARNING: previous run did not exit cleanly - probably killed by the host after ~{$lived}s "
        . '(last sign of life ' . date('H:i:s', (int)($state['heartbeat'] ?? 0)) . ')';
}

// ---------------------------------------------------------------- live check
if (!$isSuccessor) {
    $info = get_stream_info($channel);
    if ($info === false) {
        logmsg($P['label'] . ' API error while checking live status' . (!empty($GLOBALS['platformError']) ? ' (' . $GLOBALS['platformError'] . ')' : ''));
        // Kick / YouTube refusing this server for 15 minutes in a row: say so once (they block some web servers).
        if ($P['platform'] !== 'twitch') {
            $state['platform_errors'] = ($state['platform_errors'] ?? 0) + 1;
            if ($state['platform_errors'] === 15) {
                alert("{$P['label']} refuses this server ({$P['name']})",
                    "For 15 minutes the live check of '{$P['name']}' on {$P['label']} failed: " . ($GLOBALS['platformError'] ?? 'no answer') . ".\n\n"
                    . "{$P['label']} sometimes refuses web servers. Click 'Test Kick / YouTube access' on the dashboard to see why and what you can do.\n", 'warning');
            }
            save_state($state);
        }
        if ($prevKilledMsg) { logmsg($prevKilledMsg); $state['run_active'] = false; save_state($state); }
        exit(0);
    }
    if (!$info['exists']) {
        logmsg("Channel '$channel' does not exist");
        if (empty($state['not_found'])) {
            alert("Streamer '{$P['name']}' does not exist on {$P['label']}",
                "The streamer list has '{$P['name']}', but {$P['label']} has no channel with that name. Check the spelling on the dashboard (remove it and add the right name).\n");
            $state['not_found'] = true;
            save_state($state);
        }
        exit(0);
    }
    if (!empty($state['not_found']) || !empty($state['platform_errors'])) { unset($state['not_found'], $state['platform_errors']); save_state($state); }
}
$stream = $info['stream'];

if (DEBUG && !$isSuccessor) {
    logmsg('Run started - ' . ($stream ? 'channel is LIVE' : 'channel is offline, nothing to record'));
}

if (!$stream) {
    if ($prevKilledMsg) { logmsg($prevKilledMsg); $state['kills'] = ($state['kills'] ?? 0) + 1; $prevKilledMsg = null; }
    if (!empty($state['stream_id'])) {
        finalize($state, $info['display'], $chanDir);
        $state = [];
    }
    $state['run_active'] = false;
    save_state($state);

    // Keep checking until the next cron run is due, so we catch a stream start quickly.
    if (OFFLINE_CHECK_INTERVAL > 0) {
        while (microtime(true) - $runStart + OFFLINE_CHECK_INTERVAL <= 50) {
            sleep(OFFLINE_CHECK_INTERVAL);
            if (!channel_enabled($channel)) break; // switched off on the dashboard
            $check = get_stream_info($channel);
            if ($check && !empty($check['exists']) && $check['stream']) {
                $info   = $check;
                $stream = $check['stream'];
                $runStart = microtime(true);
                if (DEBUG) logmsg('Channel went LIVE during offline checks - starting now');
                break;
            }
        }
    }
    if (!$stream) {
        // Free the channel lock first, so a new stream can be recorded while we convert.
        flock($lock, LOCK_UN);
        process_conversions();
        process_uploads();
        exit(0);
    }
}

if (($state['stream_id'] ?? null) !== $stream['id']) {
    if (!empty($state['stream_id'])) {
        finalize($state, $info['display'], $chanDir);
    }
    // Same stream back after a short drop? Use a new folder, never append to a finished one.
    $dirName = date('Y-m-d_His', strtotime($stream['createdAt'])) . '_' . $stream['id'];
    for ($n = 2; is_dir("$chanDir/$dirName" . ($n > 2 ? '_part' . ($n - 1) : '')); $n++);
    $dirName .= $n > 2 ? '_part' . ($n - 1) : '';
    $state = [
        'stream_id'        => $stream['id'],
        'display'          => $info['display'],
        'dir'              => $dirName,
        'title'            => $stream['title'] ?? '',
        'game'             => $stream['game']['name'] ?? '',
        'started_at'       => $stream['createdAt'],
        'last_seq'         => -1,
        'bytes'            => 0,
        'segments'         => 0,
        'seconds'          => 0.0,
        'gaps'             => 0,
        'missing_segments' => 0,
        'ads_skipped'      => 0,
        'chat_lines'       => 0,
        'runs'             => 0,
        'kills'            => 0,
        'longest_run'      => 0,
        'recent_chat_ids'  => [],
    ];
    logmsg(sprintf(
        'LIVE: %s - "%s" (%s). Host info: PHP %s, SAPI %s, max_execution_time=%s, memory_limit=%s, MIN/MAX_RUNTIME=%d/%d',
        $info['display'], $state['title'], $state['game'], PHP_VERSION, PHP_SAPI,
        ini_get('max_execution_time'), ini_get('memory_limit'), MIN_RUNTIME, MAX_RUNTIME
    ));
    if (AUTO_CONVERT) {
        logmsg('MP4 conversion: ' . (find_ffmpeg() ? 'ffmpeg found at ' . find_ffmpeg() : 'NOT available - ' . ($GLOBALS['ffmpegProblem'] ?? '?')));
    }
} elseif ($prevKilledMsg) {
    logmsg($prevKilledMsg);
    $state['kills']++;
}

// Title and category can change during a stream, and right at the start Twitch sometimes still
// reports them empty: every run takes the latest, and changes are kept (meta.json "changes").
$titleChanged = false;
$nt = trim((string)($stream['title'] ?? ''));
$ng = trim((string)($stream['game']['name'] ?? ''));
if (($nt !== '' && $nt !== ($state['title'] ?? '')) || ($ng !== '' && $ng !== ($state['game'] ?? ''))) {
    $wasKnown = ($state['title'] ?? '') !== '' || ($state['game'] ?? '') !== '';
    if ($wasKnown) {
        if (empty($state['changes'])) { // keep what it was at the start too
            $state['changes'][] = ['time' => date('Y-m-d H:i:s', strtotime($state['started_at'])), 'video' => 0, 'title' => $state['title'], 'game' => $state['game']];
        }
        $state['changes'][] = ['time' => date('Y-m-d H:i:s'), 'video' => round((float)($state['seconds'] ?? 0)), 'title' => $nt, 'game' => $ng];
        logmsg(sprintf('Stream changed: "%s" (%s) -> "%s" (%s)', $state['title'], $state['game'], $nt ?: $state['title'], $ng ?: $state['game']));
    } else {
        logmsg(sprintf('Title now known: "%s" (%s)', $nt, $ng));
    }
    if ($nt !== '') $state['title'] = $nt;
    if ($ng !== '') $state['game'] = $ng;
    $titleChanged = true;
}

// ---------------------------------------------------------------- record
$recDir = $chanDir . '/' . $state['dir'];
@mkdir($recDir, 0755, true);
if ($titleChanged && file_exists("$recDir/meta.json")) write_meta($recDir, $state, $info['display']);

$state['run_active']  = true;
$state['run_started'] = $runStart;
$state['heartbeat']   = time();
$state['runs']++;
save_state($state);

if (!$playlistUrl) {
    $playlistUrl = get_media_playlist_url($channel, $qualityName);
}
if (!$playlistUrl) {
    logmsg('Could not get the stream playlist (stream may be starting/ending, or Twitch changed something)');
    $state['run_active'] = false;
    save_state($state);
    exit(0);
}
if ($state['runs'] === 1) {
    logmsg("Recording quality: $qualityName");
    logmsg($GLOBALS['adStatus'] ?? 'Viewer: unknown');
}

// Video is stored in parts (parts/001.ts, 002.ts ...). A new part starts at every point where the
// stream's timestamps can jump: a new viewer session, an ad break or a gap. Converting the parts
// end-to-end later is exact, instead of ffmpeg guessing across the jumps in one big file.
@mkdir("$recDir/parts", 0755, true);
if (file_exists("$recDir/video.ts") && !glob("$recDir/parts/*.ts")) {
    rename("$recDir/video.ts", "$recDir/parts/000.ts"); // recording started by an older version
}
$state['part'] = $state['part'] ?? count(glob("$recDir/parts/*.ts") ?: []);
$video   = null;
$newPart = ($state['session'] ?? null) !== $playlistUrl; // different session = possibly different timestamps
$chatFile = RECORD_CHAT ? fopen($recDir . '/chat.txt', 'ab') : null;
$chatSupported = $P['platform'] === 'twitch'; // Kick and YouTube chat: coming with their full support
if (RECORD_CHAT && !$chat && $chatSupported) {
    $chat = chat_connect($channel);
}
$lastChatTry = time();
if ($chatBuffer) {
    $state['chat_lines'] += write_chat($chatFile, $chatBuffer, $state);
    $chatBuffer = [];
}

$nextPoll   = 0.0;
$fails      = 0;
$firstFetch = true;
$diskPaused = false; // free space below MIN_FREE_GB: segments are skipped until there is room again
$runBytes   = 0;
$exitReason = 'stream ended';
$switchedOff = false;
$nextEnabledCheck = 0.0;

while (true) {
    $elapsed = microtime(true) - $runStart;
    if (microtime(true) >= $nextEnabledCheck) {
        $nextEnabledCheck = microtime(true) + 3;
        if (!channel_enabled($channel)) { $exitReason = 'switched off on the dashboard'; $switchedOff = true; break; }
        if (recheck_ad_free($channel)) {
            // Became ad-free mid-stream (e.g. you just subscribed): continue with your account.
            $u = get_media_playlist_url($channel, $qualityName, true);
            if ($u) { $playlistUrl = $u; $newPart = true; logmsg('Switched to your account (ad-free) for the rest of the stream'); }
        }
    }
    if (MAX_RUNTIME > 0) {
        if ($elapsed >= MIN_RUNTIME && file_exists($handoverFile)) { $exitReason = 'handed over'; break; }
        if ($elapsed >= MAX_RUNTIME) { $exitReason = 'MAX_RUNTIME reached, no successor'; break; }
    }

    if (microtime(true) >= $nextPoll) {
        $nextPoll = microtime(true) + POLL_INTERVAL;
        $code = 0;
        $pl = http_get($playlistUrl, 8, $code);

        if ($pl === null || $code !== 200) {
            if (++$fails >= 3) {
                $playlistUrl = get_media_playlist_url($channel, $qualityName, true);
                if (!$playlistUrl) {
                    logmsg('Playlist is gone - stream probably ended');
                    break;
                }
                $fails = 0;
                $newPart = true; // new session
            }
        } else {
            $fails = 0;
            [$segs, $ended] = parse_media_playlist($pl);

            if ($firstFetch && $segs) {
                $firstFetch = false;
                $first = $segs[0]['seq'];
                $last  = end($segs)['seq'];
                if ($state['runs'] === 1) {
                    $window = array_sum(array_column($segs, 'dur'));
                    logmsg(sprintf('Playlist window: %d segments / %.0fs', count($segs), $window));
                }
                if ($state['last_seq'] >= 0 && ($first > $state['last_seq'] + 1000 || $last < $state['last_seq'] - 10)) {
                    logmsg("Segment numbering reset (was {$state['last_seq']}, now $first-$last); continuing");
                    $state['last_seq'] = $first - 1;
                }
            }

            // Free space low: pause (the skipped part becomes a gap); resume when there is room again.
            [$free, $low] = disk_low();
            if ($low !== $diskPaused) {
                $diskPaused = $low;
                $newPart = true;
                if ($low) {
                    $msg = sprintf('Recording PAUSED: only %.1f GB free (MIN_FREE_GB = %s). It continues by itself when there is room again; delete old recordings to make room.', $free / 1073741824, MIN_FREE_GB);
                    logmsg($msg);
                    if (empty($state['disk_alerted'])) {
                        alert("Recording paused - disk almost full ({$info['display']})", $msg . "\n", 'warning');
                        $state['disk_alerted'] = true;
                    }
                } else {
                    logmsg(sprintf('Recording RESUMED: %.1f GB free again', $free / 1073741824));
                }
            }
            // Length cap: past MAX_RECORDING_HOURS the rest of the stream is not recorded.
            $capped = MAX_RECORDING_HOURS > 0 && $state['seconds'] >= MAX_RECORDING_HOURS * 3600;
            if ($capped && empty($state['capped'])) {
                $state['capped'] = true;
                $msg = sprintf('Recording stopped at %d hours (MAX_RECORDING_HOURS); the rest of this stream is not recorded. The recording is finished as usual when the stream ends.', MAX_RECORDING_HOURS);
                logmsg($msg);
                alert("Recording length cap reached ({$info['display']})", $msg . "\n", 'warning');
            }

            foreach ($segs as $s) {
                if ($s['seq'] <= $state['last_seq']) {
                    continue;
                }
                if ($capped) { $state['last_seq'] = $s['seq']; continue; }
                if ($diskPaused) {
                    $state['last_seq'] = $s['seq'];
                    $state['missing_segments']++;
                    continue;
                }
                if ($state['last_seq'] >= 0 && $s['seq'] > $state['last_seq'] + 1) {
                    $missing = $s['seq'] - $state['last_seq'] - 1;
                    $state['gaps']++;
                    $state['missing_segments'] += $missing;
                    logmsg("GAP: missed $missing segment(s) (~" . round($missing * max($s['dur'], 2)) . 's of video)');
                    $newPart = true;
                }
                $state['last_seq'] = $s['seq'];

                if ($s['ad']) {
                    $state['ads_skipped']++;
                    $newPart = true;
                    continue;
                }
                $c2 = 0;
                $data = http_get($s['url'], 15, $c2);
                if ($data === null || $c2 !== 200) {
                    $state['missing_segments']++;
                    logmsg("Segment {$s['seq']} download failed (HTTP $c2)");
                    $newPart = true;
                    continue;
                }
                if (empty($state['video_start'])) {
                    // Wall-clock time of video position 0 (also works when added mid-stream).
                    $state['video_start'] = ($s['pdt'] ?? microtime(true)) - $state['seconds'];
                    write_meta($recDir, $state, $info['display']);
                }
                if (!$video && !$newPart && $state['part'] > 0) {
                    // Same session as the previous run: keep adding to its part.
                    $video = fopen(sprintf('%s/parts/%03d.ts', $recDir, $state['part']), 'ab');
                }
                if ($newPart || !$video) {
                    if ($video) fclose($video);
                    $state['part']++;
                    $video = fopen(sprintf('%s/parts/%03d.ts', $recDir, $state['part']), 'ab');
                    $state['session'] = $playlistUrl;
                    // Where this part starts: video position (s) <-> wall-clock time, so chat stays in sync
                    // even though ad breaks and gaps are not in the video.
                    $state['timeline'][] = [round((float)$state['seconds'], 3), local_time_ms($s['pdt'] ?? microtime(true))];
                    write_meta($recDir, $state, $info['display']);
                    $newPart = false;
                }
                fwrite($video, $data);
                $len = strlen($data);
                $state['bytes']    += $len;
                $state['segments'] += 1;
                $state['seconds']  += $s['dur'];
                $runBytes          += $len;
            }

            if ($ended) {
                logmsg('Playlist reports end of stream');
                break;
            }
        }

        $state['heartbeat'] = time();
        save_state($state);
    }

    $wait = max(0.05, $nextPoll - microtime(true));
    if ($chat) {
        $new = [];
        chat_collect($chat, $new, $wait);
        if ($new && empty($state['capped'])) {
            $state['chat_lines'] += write_chat($chatFile, $new, $state);
        }
    } else {
        if (RECORD_CHAT && $chatSupported && time() - $lastChatTry >= 30) {
            $lastChatTry = time();
            $chat = chat_connect($channel);
        }
        usleep((int)($wait * 1e6));
    }
}

// ---------------------------------------------------------------- clean exit
// Grab any chat still waiting in the socket before closing.
if ($chat) {
    $new = [];
    chat_collect($chat, $new, 0.05);
    if ($new) $state['chat_lines'] += write_chat($chatFile, $new, $state);
    @fclose($chat['sock']);
}
if ($video) fclose($video);
if ($chatFile) fclose($chatFile);

$runTime = (int)round(microtime(true) - $runStart);
$state['longest_run'] = max($state['longest_run'], $runTime);
$state['run_active']  = false;
$state['heartbeat']   = time();
save_state($state);

if (DEBUG || MAX_RUNTIME === 0 || $state['runs'] % 15 === 1) {
    logmsg(sprintf(
        'Run #%d stopped after %ds (%s, +%.1f MB). Total: %.1f MB, %s recorded, %d gaps, %d ads skipped, %d chat lines',
        $state['runs'], $runTime, $exitReason, $runBytes / 1048576, $state['bytes'] / 1048576,
        gmdate('H:i:s', (int)$state['seconds']), $state['gaps'], $state['ads_skipped'], $state['chat_lines']
    ));
}
if ($switchedOff) {
    // Stopped mid-stream from the dashboard: this recording is done (chat sync, MP4, alert).
    logmsg('Recording switched off on the dashboard - wrapping up the recording');
    finalize($state, display_name($state, $chanDir, $channel), $chanDir);
    save_state(['run_active' => false]);
}
flock($lock, LOCK_UN);
exit(0);


// =================================================================
// Functions
// =================================================================

function logmsg(string $msg): void
{
    $f = $GLOBALS['logFile'];
    // Rotation: past LOG_MAX_MB the log becomes recorder.log.1 (one old copy kept) and a new one starts.
    static $checked = [];
    if (LOG_MAX_MB > 0 && (($checked[$f] ?? 0) + 60 < time())) {
        $checked[$f] = time();
        clearstatcache(true, $f);
        if ((int)@filesize($f) > LOG_MAX_MB * 1048576) {
            @rename($f, "$f.1");
            @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] Log rotated: the previous part is ' . basename($f) . ".1\n", FILE_APPEND);
        }
    }
    file_put_contents($f, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

/**
 * Text from Twitch (titles, categories, names, chat): without control characters and without the
 * invisible characters that reverse the direction of text, so it can't fake log lines or flip what
 * is shown in the dashboard, alerts or on YouTube.
 */
function clean_text(string $s): string
{
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $s = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'UTF-8') : '';
    }
    $s = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u', '', $s) ?? $s;
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s) ?? $s;
    return trim(str_replace(["\r", "\n", "\t"], ' ', $s));
}

/** Free space check for recordings, at most once a minute per process: [bytes free or null, below MIN_FREE_GB]. */
function disk_low(): array
{
    static $at = 0, $res = [null, false];
    if (time() - $at < 60) return $res;
    $at = time();
    $free = free_space_bytes();
    $res = [$free, MIN_FREE_GB > 0 && $free !== null && $free < MIN_FREE_GB * 1073741824];
    return $res;
}

function load_state(): array
{
    $f = $GLOBALS['stateFile'];
    if (!file_exists($f)) return [];
    $s = json_decode((string)file_get_contents($f), true);
    return is_array($s) ? $s : [];
}

function save_state(array $state): void
{
    $f = $GLOBALS['stateFile'];
    file_put_contents($f . '.tmp', json_encode($state, JSON_PRETTY_PRINT));
    rename($f . '.tmp', $f);
}

function http_request(string $url, ?string $post, array $headers, int $timeout, int &$code = 0): ?string
{
    $ch = curl_init($url);
    // Only https, and a request that carries a token never follows a redirect (to another site).
    $hasToken = (bool)preg_grep('/^Authorization:/i', $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => !$hasToken,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body === false ? null : $body;
}

function http_get(string $url, int $timeout, int &$code = 0): ?string
{
    return http_request($url, null, [], $timeout, $code);
}

function gql(string $query, array $vars = [], bool $auth = false, int &$code = 0): ?array
{
    $headers = ['Client-ID: ' . GQL_CLIENT_ID, 'Content-Type: application/json'];
    if ($auth && oauth_token() !== '') {
        $headers[] = 'Authorization: OAuth ' . oauth_token();
    }
    $body = http_request(
        'https://gql.twitch.tv/gql',
        json_encode(['query' => $query, 'variables' => (object)$vars]),
        $headers,
        10,
        $code
    );
    if ($body === null || $code !== 200) return null;
    $j = json_decode($body, true);
    return $j['data'] ?? null;
}

/** @return array|false  false on API error. Kick and YouTube: platforms.php, same shape. */
function get_stream_info(string $login)
{
    $P = platform_parse($login);
    if ($P && $P['platform'] !== 'twitch') return platform_stream_info($P);
    $d = gql(
        'query($login: String!) { user(login: $login) { displayName stream { id title createdAt game { name } } } }',
        ['login' => $login]
    );
    if ($d === null) return false;
    if (empty($d['user'])) return ['exists' => false];
    $stream = $d['user']['stream'];
    if (is_array($stream)) {
        // Streamer-controlled text, cleaned before it goes anywhere (logs, alerts, meta.json, YouTube).
        $stream['title'] = clean_text((string)($stream['title'] ?? ''));
        if (isset($stream['game']['name'])) $stream['game']['name'] = clean_text((string)$stream['game']['name']);
        $stream['id'] = preg_replace('/\D/', '', (string)($stream['id'] ?? '')) ?: '0';
    }
    $display = clean_text((string)$d['user']['displayName']);
    return ['exists' => true, 'display' => preg_match('/^[\p{L}\p{N}_ ]{1,40}$/u', $display) ? $display : $login, 'stream' => $stream];
}

/**
 * Per streamer (dashboard): 'off' = never use the token, 'on' = always,
 * 'auto' = use it only if your account is ad-free there.
 */
function twitch_auth_mode(): string
{
    $c = channel_settings((string)($GLOBALS['channel'] ?? ''));
    if ($c !== null && in_array($c['twitch_auth'] ?? '', ['auto', 'on', 'off'], true)) return $c['twitch_auth'];
    return twitch_auth_env();
}

/** TWITCH_AUTH from an old-style cron line. */
function twitch_auth_env(): string
{
    $v = getenv('TWITCH_AUTH');
    return $v === '0' ? 'off' : ($v === '1' ? 'on' : 'auto');
}

/** Could this channel's playback use the login token at all? */
function use_twitch_auth(): bool
{
    if (($GLOBALS['platform']['platform'] ?? 'twitch') !== 'twitch') return false; // the Twitch login is for Twitch only
    return twitch_auth_mode() !== 'off' && oauth_token() !== '';
}

/**
 * Auto mode: what Twitch said at the start of this stream about ads for your account
 * on this channel. null = not checked yet. Reset when the stream ends (and after 12 h).
 */
function auth_decision(): ?array
{
    $d = json_decode((string)@file_get_contents(dirname($GLOBALS['stateFile']) . '/.auth_decision.json'), true);
    return (is_array($d) && time() - ($d['checked'] ?? 0) < 43200) ? $d : null;
}

/**
 * Auto mode: decides from Twitch's playback token whether your account is ad-free on this channel,
 * stores the decision (.auth_decision.json, also shown on the dashboard) and logs why.
 * A direct subscription check is logged next to it, so a mismatch is visible.
 */
function decide_ad_free(string $login, array $tok): bool
{
    $v = json_decode((string)($tok['value'] ?? ''), true) ?: [];
    $uid = $v['user_id'] ?? null;
    $adFree = !empty($uid) && (isset($v['show_ads']) ? !$v['show_ads'] : (!empty($v['subscriber']) || !empty($v['turbo'])));
    $sub = subscription_check($login);
    $file = dirname($GLOBALS['stateFile']) . '/.auth_decision.json';
    $prev = json_decode((string)@file_get_contents($file), true);
    file_put_contents($file, json_encode([
        'ad_free' => $adFree, 'user_id' => $uid, 'subscriber' => $v['subscriber'] ?? null, 'turbo' => $v['turbo'] ?? null,
        'show_ads' => $v['show_ads'] ?? null, 'subscribed' => $sub['subscribed'], 'tier' => $sub['tier'], 'prime' => $sub['prime'],
        'checked' => time(),
    ]));
    $subText = $sub['subscribed'] === null ? 'unknown'
        : ($sub['subscribed'] ? 'subscribed' . ($sub['tier'] ? ' (tier ' . intdiv((int)$sub['tier'], 1000) . ($sub['prime'] ? ', Prime' : '') . ')' : '') : 'not subscribed');
    $first = !is_array($prev);
    if (!$first && (bool)$prev['ad_free'] === $adFree && !DEBUG) return $adFree; // re-check, nothing changed
    if ($adFree) {
        logmsg(sprintf('Auto: your account is ad-free on this channel (subscriber=%s turbo=%s, direct check: %s) - using your token%s',
            json_encode($v['subscriber'] ?? null), json_encode($v['turbo'] ?? null), $subText, $first ? ' for this stream' : ' from now on'));
    } elseif (empty($uid)) {
        logmsg('Auto: Twitch did not recognise your login for this request (no user id in its answer) - recording anonymously. Check the Twitch token on the dashboard.');
    } else {
        logmsg(sprintf('Auto: Twitch shows your account (user id %s) ads on this channel: subscriber=%s turbo=%s show_ads=%s; direct check: %s%s - recording anonymously, checking again every 30 min',
            $uid, json_encode($v['subscriber'] ?? null), json_encode($v['turbo'] ?? null), json_encode($v['show_ads'] ?? null), $subText,
            $sub['subscribed'] ? ' (so Twitch still marks this subscribed account for ads)' : ''));
    }
    return $adFree;
}

/** Are you subscribed to this channel? ['subscribed' => true|false|null (unknown), 'tier' => '1000'|null, 'prime' => bool] */
function subscription_check(string $login): array
{
    $d = gql('query($login: String!) { user(login: $login) { self { subscriptionBenefit { tier purchasedWithPrime } } } }', ['login' => $login], true);
    if (!is_array($d) || !isset($d['user']) || !is_array($d['user']) || !array_key_exists('self', $d['user']) || $d['user']['self'] === null) {
        return ['subscribed' => null, 'tier' => null, 'prime' => false];
    }
    $b = $d['user']['self']['subscriptionBenefit'] ?? null;
    return ['subscribed' => $b !== null, 'tier' => $b['tier'] ?? null, 'prime' => !empty($b['purchasedWithPrime'])];
}

/**
 * Auto mode, during a stream: re-check a "gets ads" decision every 30 minutes (a subscription can
 * start mid-stream). Only asks Twitch for a token, doesn't touch the running video. true = now ad-free.
 */
function recheck_ad_free(string $login): bool
{
    if (twitch_auth_mode() !== 'auto' || oauth_token() === '') return false;
    $d = auth_decision();
    if ($d === null || !empty($d['ad_free']) || time() - (int)($d['checked'] ?? 0) < 1800) return false;
    $r = gql('query($login: String!) { streamPlaybackAccessToken(channelName: $login, params: {platform: "web", playerBackend: "mediaplayer", playerType: "embed"}) { value signature } }',
        ['login' => $login], true);
    $tok = $r['streamPlaybackAccessToken'] ?? null;
    if (!$tok) { // couldn't ask: try again later
        $d['checked'] = time();
        @file_put_contents(dirname($GLOBALS['stateFile']) . '/.auth_decision.json', json_encode($d));
        return false;
    }
    return decide_ad_free($login, $tok);
}

/** Use the token for this request? (auto mode: only while the account is ad-free here) */
function want_twitch_auth(): bool
{
    if (!use_twitch_auth()) return false;
    if (twitch_auth_mode() === 'on') return true;
    $d = auth_decision();
    return $d === null || !empty($d['ad_free']);
}

/**
 * Stream playlist URL. Reuses the previous run's playlist while its playback token
 * is valid, so the relay asks Twitch for a new one only when needed (like a viewer
 * who keeps watching), instead of once per cron run. $fresh = always ask Twitch.
 */
function get_media_playlist_url(string $login, string &$chosen = '', bool $fresh = false): ?string
{
    $P = platform_parse($login);
    if ($P && $P['platform'] !== 'twitch') {
        $GLOBALS['adStatus'] = 'Viewer: anonymous (' . $P['label'] . ')';
        return platform_playlist($P, $chosen, $fresh);
    }
    $cacheFile = dirname($GLOBALS['stateFile']) . '/.playlist.json';
    if (!$fresh) {
        $c = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
        if (!empty($c['url']) && ($c['expires'] ?? 0) > time() + 120 && ($c['auth'] ?? null) === want_twitch_auth()) {
            $chosen = $c['quality'] ?? '';
            $GLOBALS['adStatus'] = $c['adStatus'] ?? null;
            return $c['url'];
        }
    }

    $query = 'query($login: String!) { streamPlaybackAccessToken(channelName: $login, params: {platform: "web", playerBackend: "mediaplayer", playerType: "embed"}) { value signature } }';
    $tok = null;
    $declined = false; // auto mode decided not to use the token
    if (want_twitch_auth()) {
        $code = 0;
        $d = gql($query, ['login' => $login], true, $code);
        $tok = $d['streamPlaybackAccessToken'] ?? null;
        if ($tok && twitch_auth_mode() === 'auto' && auth_decision() === null) {
            // Twitch's answer says whether this account gets ads here - no extra request needed.
            if (!decide_ad_free($login, $tok)) {
                $tok = null; // fall through to an anonymous token below
                $declined = true;
            }
        }
        if (!$tok && !$declined && empty($GLOBALS['authFailLogged'])) {
            $GLOBALS['authFailLogged'] = true;
            logmsg("Login token was rejected (HTTP $code) - expired or wrong? Falling back to anonymous (with ads)");
            // Let the next cron run check the token straight away (and alert if it's broken).
            $sf = secret_dir() . '/token_status.json';
            $st = json_decode((string)@file_get_contents($sf), true) ?: [];
            $st['checked'] = 0;
            @file_put_contents($sf, json_encode($st));
        }
    }
    if (!$tok) {
        $d = gql($query, ['login' => $login]);
        $tok = $d['streamPlaybackAccessToken'] ?? null;
    }
    if (!$tok) return null;

    // What Twitch decided about ads for this viewer (logged once per stream).
    $v = json_decode($tok['value'], true) ?: [];
    $expires = (int)($v['expires'] ?? 0);
    $GLOBALS['adStatus'] = sprintf(
        'Viewer: %s | subscriber=%s turbo=%s show_ads=%s | playback token valid %s',
        !empty($v['user_id']) ? 'logged in (user id ' . $v['user_id'] . ')' : 'anonymous',
        json_encode($v['subscriber'] ?? null), json_encode($v['turbo'] ?? null), json_encode($v['show_ads'] ?? null),
        $expires > time() ? '~' . round(($expires - time()) / 60) . ' min' : 'unknown'
    );

    $q = http_build_query([
        'sig'                        => $tok['signature'],
        'token'                      => $tok['value'],
        'allow_source'               => 'true',
        'allow_audio_only'           => 'true',
        'fast_bread'                 => 'true',
        'playlist_include_framerate' => 'true',
        'player'                     => 'twitchweb',
        'p'                          => random_int(100000, 999999),
    ]);
    $code = 0;
    $master = http_get("https://usher.ttvnw.net/api/channel/hls/$login.m3u8?$q", 10, $code);
    if ($master === null || $code !== 200) return null;
    $url = pick_variant($master, $chosen);
    if ($url) {
        file_put_contents($cacheFile, json_encode([
            'url' => $url, 'quality' => $chosen, 'adStatus' => $GLOBALS['adStatus'], 'auth' => want_twitch_auth(),
            // Unknown lifetime: reuse for 10 minutes at most.
            'expires' => $expires > time() ? $expires : time() + 600,
        ]));
        @chmod($cacheFile, 0600);
    }
    return $url;
}

function pick_variant(string $m3u8, string &$chosen = ''): ?string
{
    $lines = preg_split('/\r?\n/', trim($m3u8));
    $names = [];
    $variants = [];
    foreach ($lines as $i => $l) {
        if (strpos($l, '#EXT-X-MEDIA:') === 0) {
            if (preg_match('/GROUP-ID="([^"]+)"/', $l, $g) && preg_match('/NAME="([^"]+)"/', $l, $n)) {
                $names[$g[1]] = $n[1];
            }
        } elseif (strpos($l, '#EXT-X-STREAM-INF:') === 0) {
            preg_match('/BANDWIDTH=(\d+)/', $l, $b);
            preg_match('/VIDEO="([^"]+)"/', $l, $v);
            $group = $v[1] ?? '';
            // Kick and YouTube may give only a resolution: name those "720p" and so on.
            $res = preg_match('/RESOLUTION=\d+x(\d+)/', $l, $r) ? $r[1] . 'p' : '';
            $variants[] = [
                'name'  => $names[$group] ?? ($group !== '' ? $group : $res),
                'group' => $group,
                'bw'    => (int)($b[1] ?? 0),
                'url'   => trim($lines[$i + 1] ?? ''),
            ];
        }
    }
    if (!$variants) return null;

    if (QUALITY !== 'best') {
        foreach ($variants as $v) {
            if ($v['group'] === QUALITY || strpos($v['name'], QUALITY) === 0) {
                $chosen = $v['name'];
                return $v['url'];
            }
        }
    }
    $video = array_filter($variants, fn($v) => $v['group'] !== 'audio_only');
    if (!$video) $video = $variants;
    usort($video, fn($a, $b) => $b['bw'] <=> $a['bw']);
    $chosen = $video[0]['name'] . (QUALITY !== 'best' ? ' (wanted ' . QUALITY . ', not offered)' : '');
    return $video[0]['url'];
}

function parse_media_playlist(string $m3u8): array
{
    $seq = 0;
    $segs = [];
    $ended = false;
    $dur = 0.0;
    $title = '';
    $pdt = null;
    foreach (preg_split('/\r?\n/', $m3u8) as $l) {
        $l = trim($l);
        if ($l === '') continue;
        if (strpos($l, '#EXT-X-MEDIA-SEQUENCE:') === 0) {
            $seq = (int)substr($l, 22);
        } elseif (strpos($l, '#EXT-X-PROGRAM-DATE-TIME:') === 0) {
            // Wall-clock time the segment was broadcast - used to sync chat to video.
            $t = strtotime(substr($l, 25));
            if ($t !== false) {
                $pdt = $t + (preg_match('/\.(\d+)/', $l, $ms) ? (float)('0.' . $ms[1]) : 0.0);
            }
        } elseif (strpos($l, '#EXTINF:') === 0) {
            $parts = explode(',', substr($l, 8), 2);
            $dur = (float)$parts[0];
            $title = $parts[1] ?? '';
        } elseif ($l === '#EXT-X-ENDLIST') {
            $ended = true;
        } elseif ($l[0] !== '#') {
            // Real stream segments are titled "live"; injected ads carry another title.
            $isAd = $title !== '' && stripos($title, 'live') === false;
            $segs[] = ['seq' => $seq, 'url' => $l, 'dur' => $dur, 'ad' => $isAd, 'pdt' => $pdt];
            $seq++;
            $dur = 0.0;
            $title = '';
            $pdt = null;
        }
    }
    return [$segs, $ended];
}

// ---------------------------------------------------------------- chat (anonymous, WebSocket on port 443)

function chat_connect(string $channel): ?array
{
    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => CHAT_HOST]]);
    $s = @stream_socket_client('ssl://' . CHAT_HOST . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        logmsg("Chat connect failed: $errstr ($errno)");
        return null;
    }
    stream_set_timeout($s, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($s, "GET / HTTP/1.1\r\nHost: " . CHAT_HOST . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nOrigin: https://www.twitch.tv\r\n\r\n");

    $resp = '';
    $deadline = microtime(true) + 5;
    while (strpos($resp, "\r\n\r\n") === false && microtime(true) < $deadline) {
        $c = fread($s, 2048);
        if ($c === false || ($c === '' && feof($s))) break;
        $resp .= $c;
    }
    if (!preg_match('#^HTTP/1\.1 101#', $resp)) {
        logmsg('Chat WebSocket handshake failed: ' . strtok($resp ?: 'no response', "\r\n"));
        fclose($s);
        return null;
    }
    $chat = ['sock' => $s, 'raw' => (string)substr($resp, strpos($resp, "\r\n\r\n") + 4)];
    stream_set_blocking($s, false);

    $nick = 'justinfan' . random_int(10000, 99999);
    foreach (['CAP REQ :twitch.tv/tags twitch.tv/commands', 'PASS SCHMOOPIIE', "NICK $nick", "JOIN #$channel"] as $cmd) {
        ws_send($chat, $cmd);
    }
    return $chat;
}

function ws_send(array $chat, string $text, int $opcode = 0x1): void
{
    $len = strlen($text);
    $frame = chr(0x80 | $opcode);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $frame .= $mask;
    for ($i = 0; $i < $len; $i++) {
        $frame .= $text[$i] ^ $mask[$i % 4];
    }
    for ($tries = 0; $frame !== '' && $tries < 50; $tries++) {
        $n = @fwrite($chat['sock'], $frame);
        if ($n === false) return;
        $frame = (string)substr($frame, $n);
        if ($frame !== '') usleep(10000);
    }
}

/** Waits up to $timeout s for chat, appends parsed messages to $out. Sets $chat = null on disconnect. */
function chat_collect(?array &$chat, array &$out, float $timeout): void
{
    $r = [$chat['sock']];
    $w = $e = null;
    $sec = (int)$timeout;
    $usec = (int)(($timeout - $sec) * 1e6);
    @stream_select($r, $w, $e, $sec, $usec);

    // Read everything available (also data already decrypted in PHP's SSL buffer).
    while (true) {
        $data = @fread($chat['sock'], 65536);
        if ($data === false || $data === '') break;
        $chat['raw'] .= $data;
    }
    if (feof($chat['sock'])) {
        logmsg('Chat disconnected (will retry)');
        @fclose($chat['sock']);
        $chat = null;
        return;
    }

    // Decode WebSocket frames.
    while (strlen($chat['raw']) >= 2) {
        $b = $chat['raw'];
        $op = ord($b[0]) & 0x0f;
        $len = ord($b[1]) & 0x7f;
        $off = 2;
        if ($len === 126) {
            if (strlen($b) < 4) break;
            $len = unpack('n', substr($b, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (strlen($b) < 10) break;
            $len = unpack('J', substr($b, 2, 8))[1];
            $off = 10;
        }
        if (strlen($b) < $off + $len) break; // frame not complete yet
        $payload = substr($b, $off, $len);
        $chat['raw'] = (string)substr($b, $off + $len);

        if ($op === 0x9) {            // ping
            ws_send($chat, $payload, 0xA);
        } elseif ($op === 0x8) {      // close
            logmsg('Chat closed by Twitch (will retry)');
            @fclose($chat['sock']);
            $chat = null;
            return;
        } elseif ($op === 0x1 || $op === 0x0) {
            foreach (explode("\r\n", $payload) as $line) {
                if ($line === '') continue;
                if (strpos($line, 'PING') === 0) {
                    ws_send($chat, 'PONG' . substr($line, 4));
                    continue;
                }
                $msg = format_chat_line($line);
                if ($msg !== null) $out[] = $msg;
            }
        }
    }
}

/** Writes messages not written before (by this or the previous run). Returns count written. */
function write_chat($file, array $msgs, array &$state): int
{
    $seen = array_flip($state['recent_chat_ids'] ?? []);
    $n = 0;
    foreach ($msgs as $m) {
        if (isset($seen[$m['id']])) continue;
        $seen[$m['id']] = true;
        $state['recent_chat_ids'][] = $m['id'];
        fwrite($file, '[' . date('Y-m-d H:i:s', (int)$m['ts']) . '] ' . $m['text'] . "\n");
        $n++;
    }
    if (count($state['recent_chat_ids'] ?? []) > 1000) {
        $state['recent_chat_ids'] = array_slice($state['recent_chat_ids'], -1000);
    }
    return $n;
}

/** @return array|null ['id' => ..., 'ts' => unix time, 'text' => ...] */
function format_chat_line(string $raw): ?array
{
    $line = $raw;
    $tags = [];
    if ($line !== '' && $line[0] === '@') {
        [$t, $line] = array_pad(explode(' ', $line, 2), 2, '');
        foreach (explode(';', substr($t, 1)) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $tags[$k] = $v;
        }
    }
    if (!preg_match('/^:(\S+) (\S+) (\S+)(?: :(.*))?$/', $line, $m)) return null;
    $nick = strtok($m[1], '!');
    $name = ($tags['display-name'] ?? '') !== '' ? $tags['display-name'] : $nick;
    if (!preg_match('/^[\p{L}\p{N}_]{1,40}$/u', $name)) $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$nick)) ?: 'someone';
    $msg  = $m[4] ?? null;

    switch ($m[2]) {
        case 'PRIVMSG':
            $text = preg_match('/^\x01ACTION (.*)\x01$/', (string)$msg, $a) ? "* $name {$a[1]}" : "$name: $msg";
            break;
        case 'USERNOTICE': // subs, gift subs, raids
            $sys = str_replace(['\s', '\:', '\\\\'], [' ', ';', '\\'], $tags['system-msg'] ?? '');
            $text = "** $sys" . ($msg !== null ? " | $name: $msg" : '');
            break;
        case 'CLEARCHAT':
            if ($msg === null) {
                $text = '** Chat was cleared by a moderator';
            } else {
                $text = isset($tags['ban-duration'])
                    ? "** $msg was timed out for {$tags['ban-duration']}s"
                    : "** $msg was banned";
            }
            break;
        default:
            return null;
    }
    $ts = isset($tags['tmi-sent-ts']) ? ((int)$tags['tmi-sent-ts']) / 1000 : time();
    return ['id' => $tags['id'] ?? md5($raw), 'ts' => $ts, 'text' => clean_text($text)];
}

// ---------------------------------------------------------------- token storage (encrypted)

function secret_dir(): string
{
    $dir = SECRET_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    if (!file_exists("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    }
    // Dashboard key (see README). dashboard_key.txt holds the key for you to copy; dashboard_key.hash is
    // what the dashboard checks. After logging in you may delete the .txt - your browsers stay logged in.
    // Delete dashboard_key.hash to log every browser out; a new key is then created.
    if (!file_exists("$dir/dashboard_key.hash")) {
        if (!file_exists("$dir/dashboard_key.txt")) {
            file_put_contents("$dir/dashboard_key.txt", rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='));
            @chmod("$dir/dashboard_key.txt", 0600);
            if (!empty($GLOBALS['logFile'])) logmsg('Dashboard key created: secret/dashboard_key.txt');
        }
        file_put_contents("$dir/dashboard_key.hash", hash('sha256', trim((string)file_get_contents("$dir/dashboard_key.txt"))));
        @chmod("$dir/dashboard_key.hash", 0600);
    }
    return $dir;
}

/** Key derived from the REC_KEY passphrase (cron line / start script environment). null if missing. */
function secret_key(string $salt): ?string
{
    $pass = getenv('REC_KEY');
    if ($pass === false || $pass === '') return null;
    return hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
}

/** Returns the Twitch token ('' if none / unreadable). Encrypts a new token_setup.txt first. */
function oauth_token(): string
{
    static $tok = null;
    if ($tok !== null) return $tok;
    $tok = '';
    $GLOBALS['tokenProblem'] = null;

    $dir   = secret_dir();
    $setup = "$dir/token_setup.txt";
    $enc   = "$dir/token.enc";

    if (file_exists($setup)) {
        $plain = trim((string)file_get_contents($setup));
        $plain = preg_replace('/^OAuth\s+/i', '', $plain);
        if ($plain !== '') {
            $salt = random_bytes(16);
            $key  = secret_key($salt);
            if ($key === null) {
                $GLOBALS['tokenProblem'] = 'token_setup.txt found, but REC_KEY is missing (cron line / start script) - token was NOT stored';
                logmsg($GLOBALS['tokenProblem']);
                return '';
            }
            $iv  = random_bytes(12);
            $tag = '';
            $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            file_put_contents($enc . '.tmp', json_encode([
                'v' => 1, 'salt' => base64_encode($salt), 'iv' => base64_encode($iv),
                'tag' => base64_encode($tag), 'ct' => base64_encode($ct),
            ]));
            @chmod($enc . '.tmp', 0600);
            rename($enc . '.tmp', $enc);
            // Overwrite before deleting, so the plain token isn't left on disk.
            file_put_contents($setup, str_repeat('x', strlen($plain)));
            unlink($setup);
            @unlink("$dir/token_status.json"); // fresh token: start health tracking over
            logmsg('New Twitch token encrypted into secret/token.enc - token_setup.txt deleted');
            return $tok = $plain;
        }
    }

    if (!file_exists($enc)) return '';
    $j   = json_decode((string)file_get_contents($enc), true) ?: [];
    $key = secret_key(base64_decode($j['salt'] ?? ''));
    if ($key === null) {
        $GLOBALS['tokenProblem'] = 'secret/token.enc exists, but REC_KEY is missing (cron line / start script)';
        return '';
    }
    $p = openssl_decrypt(
        base64_decode($j['ct'] ?? ''), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        base64_decode($j['iv'] ?? ''), base64_decode($j['tag'] ?? '')
    );
    if ($p === false) {
        $GLOBALS['tokenProblem'] = 'secret/token.enc could not be decrypted - REC_KEY (cron line / start script) is wrong';
        return '';
    }
    return $tok = $p;
}

/**
 * Checks (throttled, shared by all channels) whether the token still works,
 * and alerts on changes: broken, still broken (daily), working again, expiring soon.
 */
function check_token_health(): void
{
    $dir = secret_dir();
    if (!file_exists("$dir/token.enc") && !file_exists("$dir/token_setup.txt")) {
        return; // no token configured: anonymous recording
    }
    $statusFile = "$dir/token_status.json";
    $st = file_exists($statusFile) ? (json_decode((string)file_get_contents($statusFile), true) ?: []) : [];
    $needName = ($st['status'] ?? '') === 'valid' && empty($st['login']) && time() - ($st['checked'] ?? 0) >= 600; // the player needs your Twitch name: get it soon
    if (!$needName && time() - ($st['checked'] ?? 0) < TOKEN_CHECK_INTERVAL - 5) return;

    $lk = fopen("$dir/.check.lock", 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return; // another channel's run is checking

    $tok     = oauth_token();
    $status  = null;   // 'valid' | 'invalid' | null (couldn't tell: network problem)
    $detail  = '';
    $expires = 0;

    if ($tok === '') {
        $status = 'invalid';
        $detail = $GLOBALS['tokenProblem'] ?? 'no token could be loaded';
    } else {
        $code = 0;
        $body = http_request('https://id.twitch.tv/oauth2/validate', null, ['Authorization: OAuth ' . $tok], 10, $code);
        if ($code === 200) {
            $v = json_decode((string)$body, true) ?: [];
            $status  = 'valid';
            $expires = (int)($v['expires_in'] ?? 0);
            $detail  = 'logged in as ' . ($v['login'] ?? '?');
            $st['login'] = (string)($v['login'] ?? '');
            $st['user_id'] = (string)($v['user_id'] ?? '');
        } elseif ($code === 401) {
            $status = 'invalid';
            $detail = 'Twitch rejected the token (logged out, password changed, or expired)';
        } else {
            // Fallback check via the same API the recorder uses.
            $d = gql('query { currentUser { login } }', [], true, $code);
            if ($code === 401 || ($d !== null && empty($d['currentUser']))) {
                $status = 'invalid';
                $detail = 'Twitch rejected the token';
            } elseif (!empty($d['currentUser']['login'])) {
                $status = 'valid';
                $detail = 'logged in as ' . $d['currentUser']['login'];
                $st['login'] = (string)$d['currentUser']['login'];
            }
        }
    }

    $st['checked'] = time();
    if ($status !== null) {
        $prev = $st['status'] ?? 'unknown';
        $now  = time();
        if ($status === 'invalid') {
            if ($prev !== 'invalid') {
                logmsg("TOKEN PROBLEM: $detail - recording continues anonymously (with ads cut out)");
                alert('Twitch token stopped working', token_alert_text($detail));
                $st['alerted'] = $now;
            } elseif ($now - ($st['alerted'] ?? 0) >= 86400) {
                alert('Reminder: Twitch token still not working', token_alert_text($detail));
                $st['alerted'] = $now;
            }
        } else {
            if ($prev === 'invalid') {
                logmsg("Token works again ($detail)");
                alert('Twitch token works again', "Good news: the Twitch token works again ($detail).\nAd-free recording is back on.\n");
            } elseif ($prev === 'unknown' && DEBUG) {
                logmsg("Token OK ($detail" . ($expires > 0 ? ', expires in ' . round($expires / 86400, 1) . ' days' : '') . ')');
            }
            if ($expires > 0 && $expires < 3 * 86400 && empty($st['expiry_warned'])) {
                alert('Twitch token expires soon',
                    'The Twitch token expires in about ' . round($expires / 3600) . " hours.\n\n" . token_alert_text('expires soon'));
                $st['expiry_warned'] = true;
            }
        }
        $st['status'] = $status;
        $st['detail'] = $detail;
    }
    file_put_contents($statusFile, json_encode($st, JSON_PRETTY_PRINT));
    @chmod($statusFile, 0600);
    flock($lk, LOCK_UN);
}

function token_alert_text(string $detail): string
{
    return "The Twitch token used by the stream recorder isn't working: $detail.\n\n"
        . "Recording continues, but anonymously: ads are cut out, leaving short jumps.\n\n"
        . "To fix:\n"
        . "1. Open twitch.tv in a private/incognito window and log in.\n"
        . "2. F12 -> Application (or Storage) -> Cookies -> https://www.twitch.tv -> copy the value of auth-token.\n"
        . "3. Close the window WITHOUT logging out.\n"
        . "4. Put the token in secret/token_setup.txt in the recorder folder (next to record.php).\n"
        . "   The next cron run encrypts it and deletes that file.\n";
}

/** Every notification is kept for the dashboard's alert list (newest first, last 100). */
function record_notification(string $subject, string $body, ?string $level = null): void
{
    $level = in_array($level, ['warning', 'success', 'info'], true) ? $level : (preg_match('/fail|stopped working|problem|shorter|reminder|not working|gave up|not enough|does not exist/i', $subject) ? 'warning'
        : (preg_match('/uploaded|works again|ready/i', $subject) ? 'success' : 'info'));
    $f = RECORDINGS_DIR . '/.notifications.json';
    $h = fopen($f, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $list = json_decode((string)stream_get_contents($h), true) ?: [];
    array_unshift($list, [
        'id'      => sprintf('%.6f', microtime(true)),
        'time'    => date('Y-m-d H:i:s'),
        'level'   => $level,
        'channel' => basename((string)($GLOBALS['logFile'] ?? '')) === '.system.log' ? '' : basename(dirname((string)($GLOBALS['logFile'] ?? ''))),
        'subject' => $subject,
        'body'    => function_exists('mb_strcut') ? mb_strcut($body, 0, 3000) : substr($body, 0, 3000),
    ]);
    $list = array_slice($list, 0, 100);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($h, LOCK_UN);
    fclose($h);
}

/** Shows a message in the dashboard's Alerts (and the channel log). */
/** $level: 'warning' | 'success' | 'info'; null = decided from the subject (only for subjects without titles). */
function alert(string $subject, string $body, ?string $level = null): void
{
    $subject = clean_text(preg_replace('/^\[Recorder\]\s*/', '', $subject));
    logmsg("Alert: $subject");
    record_notification($subject, $body, $level);
}

// ---------------------------------------------------------------- MP4 conversion

function exec_available(): bool
{
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function find_ffmpeg(): ?string
{
    static $found = false;
    if ($found !== false) return $found;
    $found = null;
    if (!exec_available()) {
        $GLOBALS['ffmpegProblem'] = 'PHP exec() is disabled on this host';
        return null;
    }
    $candidates = array_filter([FFMPEG_PATH, __DIR__ . '/bin/ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/bin/ffmpeg']);
    foreach ($candidates as $c) {
        if (@is_file($c) && @is_executable($c)) { $found = $c; break; }
    }
    if (!$found) {
        $o = trim((string)@exec('command -v ffmpeg 2>/dev/null'));
        if ($o !== '') $found = $o;
    }
    if (!$found) {
        $GLOBALS['ffmpegProblem'] = 'no ffmpeg on the server (install ffmpeg, or put a static build at bin/ffmpeg next to record.php - see docs/install-webserver.md)';
        return null;
    }
    $out = [];
    $rc = 1;
    @exec(escapeshellarg($found) . ' -version 2>&1', $out, $rc);
    if ($rc !== 0) {
        $GLOBALS['ffmpegProblem'] = "found $found but it won't run: " . ($out[0] ?? "exit code $rc");
        $found = null;
    }
    return $found;
}

/** Read-modify-write of a job queue ('convert' or 'upload') under a file lock. */
function queue_update(callable $fn, string $name = 'convert'): void
{
    $f = RECORDINGS_DIR . "/.{$name}_queue.json";
    $h = fopen($f, 'c+');
    flock($h, LOCK_EX);
    $q = json_decode((string)stream_get_contents($h), true) ?: [];
    $fn($q);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($q));
    flock($h, LOCK_UN);
    fclose($h);
}

function queue_conversion(string $recDir, float $seconds, ?string $youtube): void
{
    queue_update(function (array &$q) use ($recDir, $seconds, $youtube) {
        $q[$recDir] = $q[$recDir] ?? ['tries' => 0, 'seconds' => $seconds, 'youtube' => $youtube];
    });
}

/** Duration in seconds of a media file according to ffmpeg, or 0. */
function media_duration(string $ff, string $file): float
{
    $out = [];
    @exec(escapeshellarg($ff) . ' -hide_banner -i ' . escapeshellarg($file) . ' 2>&1 | grep -m1 Duration', $out);
    foreach ($out as $l) {
        if (preg_match('/Duration: (\d+):(\d+):(\d+(?:\.\d+)?)/', $l, $m)) {
            return $m[1] * 3600 + $m[2] * 60 + (float)$m[3];
        }
    }
    return 0.0;
}

/** The recorded video files of a recording folder, in order (parts, or a single video.ts from older versions). */
function video_sources(string $dir): array
{
    $parts = glob("$dir/parts/*.ts") ?: [];
    sort($parts);
    if ($parts) return $parts;
    return file_exists("$dir/video.ts") ? ["$dir/video.ts"] : [];
}

/** Free space in bytes: the smaller of the disk's free space and the account quota (if the `quota` command tells). null = unknown. */
function free_space_bytes(): ?float
{
    $free = @disk_free_space(RECORDINGS_DIR);
    $free = $free === false ? null : (float)$free;
    if (exec_available()) {
        $out = [];
        @exec('quota -w 2>/dev/null', $out);
        foreach ($out as $l) {
            // "  /dev/xyz  123456*  5000000  5000000 ..." (1K blocks: used, soft limit, hard limit)
            if (preg_match('#^\s*\S+\s+(\d+)\*?\s+(\d+)\s+(\d+)#', $l, $m)) {
                $limit = (float)($m[3] > 0 ? $m[3] : $m[2]) * 1024;
                if ($limit > 0) {
                    $q = max(0, $limit - (float)$m[1] * 1024);
                    $free = $free === null ? $q : min($free, $q);
                }
            }
        }
    }
    return $free;
}

/** Is any channel recording right now? (conversions and uploads wait for that, to stay under the host's IO limit) */
function any_channel_recording(): bool
{
    foreach (glob(RECORDINGS_DIR . '/*/state.json') ?: [] as $f) {
        $st = json_decode((string)@file_get_contents($f), true) ?: [];
        if (!empty($st['stream_id']) && time() - (int)($st['heartbeat'] ?? 0) < 90) return true;
    }
    return false;
}

/** Converts queued recordings, one process at a time (all channels share the queue). */
function process_conversions(): void
{
    // Manual retry: an (empty) file named convert-again.txt in a recording folder queues it again.
    foreach (glob(RECORDINGS_DIR . '/*/*/convert-again.txt') ?: [] as $f) {
        $dir  = dirname($f);
        $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
        unlink($f);
        if (!video_sources($dir)) continue;
        if (conversion_running($dir, ['started' => 0])) { // that one is still busy: leave it
            $own = $GLOBALS['logFile'];
            $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
            logmsg('convert-again.txt ignored for ' . basename($dir) . ': it is converting right now (use STOP-CONVERSIONS.txt first to restart it)');
            $GLOBALS['logFile'] = $own;
            continue;
        }
        @unlink("$dir/video.mp4"); // replaced by the new attempt
        unset($meta['mp4_incomplete']);
        file_put_contents("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        queue_update(function (array &$q) use ($dir) { unset($q[$dir]); }); // fresh attempt count
        queue_conversion($dir, (float)($meta['recorded_seconds'] ?? 0), null);
        $own = $GLOBALS['logFile'];
        $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
        logmsg('MP4 conversion queued again for ' . basename($dir) . ' (convert-again.txt)');
        $GLOBALS['logFile'] = $own;
    }
    if (!AUTO_CONVERT || !file_exists(RECORDINGS_DIR . '/.convert_queue.json')) return;
    if (file_exists(__DIR__ . '/STOP-CONVERSIONS.txt')) return;
    $lk = fopen(RECORDINGS_DIR . '/.convert.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return; // another run is handling the queue right now

    // ffmpeg runs in the background, detached from these short cron runs (the host stops long PHP
    // runs, and used to take the waiting PHP down mid-conversion). Each run checks on it, and when
    // it is done, checks the result and starts the next one. One conversion at a time.
    $ownLog  = $GLOBALS['logFile'];
    $toStart = null;
    while (true) {
        $job = null;
        queue_update(function (array &$q) use (&$job) {
            foreach ($q as $dir => $j) { // a running conversion first
                if (!empty($j['started'])) { $job = [$dir, $j]; return; }
            }
            foreach ($q as $dir => $j) {
                if (($j['not_before'] ?? 0) > time()) continue; // waiting for disk space
                $job = [$dir, $j];
                return;
            }
        });
        if (!$job) break;
        [$dir, $j] = $job;
        $GLOBALS['logFile'] = dirname($dir) . '/recorder.log'; // log in that channel's log
        $tries = (int)($j['tries'] ?? 0);

        if (!empty($j['started'])) {
            if (conversion_running($dir, $j)) break; // still busy: check again next run

            $result = finish_conversion($dir, $tries, (float)$j['seconds'], (int)$j['started']);
            if ($result === 'killed') {
                if ($tries >= CONVERT_ATTEMPTS) {
                    queue_update(function (array &$q) use ($dir) { unset($q[$dir]); });
                    alert('MP4 conversion gave up: ' . basename($dir),
                        "ffmpeg was stopped before it finished $tries times, so the conversion was stopped. Nothing was deleted: the .ts part(s) are still there.\n\n"
                        . "Folder: $dir\nPut an empty convert-again.txt in that folder to try again.\n");
                    if (!empty($j['youtube'])) queue_upload($dir, $j['youtube']);
                } else {
                    queue_update(function (array &$q) use ($dir) { if (isset($q[$dir])) unset($q[$dir]['started']); });
                }
                continue;
            }
            $final = $result !== 'error' || $tries >= CONVERT_ATTEMPTS;
            queue_update(function (array &$q) use ($dir, $final) {
                if ($final) unset($q[$dir]);
                elseif (isset($q[$dir])) unset($q[$dir]['started']); // finished with an error: retry
            });
            if ($result === 'error' && $final) {
                alert('MP4 conversion failed: ' . basename($dir),
                    "Converting this recording to MP4 failed " . CONVERT_ATTEMPTS . " times, so it stays video.ts (nothing was lost; VLC plays it).\n\n"
                    . "Folder: $dir\nDetails: conversion.log in that folder and recorder.log.\n\n"
                    . "You can convert it on your PC:\n  ffmpeg -i video.ts -c copy video.mp4\n");
            }
            // Conversion done (or given up, video.ts kept): now it may go to YouTube.
            if (!empty($j['youtube']) && $final) queue_upload($dir, $j['youtube']);
            continue;
        }

        if (CONVERT_WHEN_IDLE && any_channel_recording()) break; // start when nothing records

        // Enough room? The MP4 needs about as much space as the recording.
        $sources = video_sources($dir);
        $need = array_sum(array_map('filesize', $sources)) * 1.05 + 512 * 1048576;
        $free = free_space_bytes();
        if ($sources && $free !== null && $free < $need) {
            $msg = sprintf('Not enough disk space to convert %s: it needs ~%.1f GB free, there is %.1f GB. Delete old recordings (or their .ts once you have the MP4); it retries automatically every 30 minutes.',
                basename($dir), $need / 1073741824, $free / 1073741824);
            logmsg($msg);
            if (empty($j['space_alerted'])) alert('Not enough disk space to convert: ' . basename($dir), $msg . "\n");
            queue_update(function (array &$q) use ($dir) {
                if (!isset($q[$dir])) return;
                $q[$dir]['space_alerted'] = true;
                $q[$dir]['not_before'] = time() + 1800;
            });
            continue;
        }
        if ($free !== null) logmsg(sprintf('Disk space before converting: %.1f GB free, ~%.1f GB needed', $free / 1073741824, $need / 1073741824));
        queue_update(function (array &$q) use ($dir) {
            if (!isset($q[$dir])) return;
            $q[$dir]['tries'] = ($q[$dir]['tries'] ?? 0) + 1; // counted up front, in case it gets cut off
            $q[$dir]['started'] = time();
            unset($q[$dir]['space_alerted'], $q[$dir]['not_before']);
        });
        $toStart = [$dir, $tries + 1, $GLOBALS['logFile']];
        break;
    }
    // Unlock BEFORE starting ffmpeg: a background process would otherwise inherit and hold the lock.
    flock($lk, LOCK_UN);
    fclose($lk);
    if ($toStart) {
        [$dir, $attempt, $GLOBALS['logFile']] = $toStart;
        if (!start_conversion($dir, $attempt)) {
            // Could not even start (no ffmpeg / exec): record a failed attempt, the next run decides.
            @file_put_contents("$dir/.convert_exit", "127\n");
        }
    }
    $GLOBALS['logFile'] = $ownLog;
}

/**
 * Counts warning types in an ffmpeg log without loading it into memory, keeps its last lines,
 * and shrinks a huge log to its first and last 500 KB. Returns [summary, last 2 lines].
 */
function summarize_ffmpeg_log(string $log): array
{
    $n = ['jump' => 0, 'mono' => 0, 'corrupt' => 0, 'lines' => 0];
    $last = [];
    $h = @fopen($log, 'rb');
    if ($h) {
        while (($l = fgets($h, 8192)) !== false) {
            $l = trim($l);
            if ($l === '') continue;
            $n['lines']++;
            if (stripos($l, 'discontinuity') !== false) $n['jump']++;
            elseif (stripos($l, 'non-monotonic') !== false) $n['mono']++;
            elseif (preg_match('/corrupt|invalid|error/i', $l)) $n['corrupt']++;
            $last[] = $l;
            if (count($last) > 2) array_shift($last);
        }
        fclose($h);
    }
    $size = (int)@filesize($log);
    if ($size > 2 * 1048576) {
        $head = (string)file_get_contents($log, false, null, 0, 512000);
        $tail = (string)file_get_contents($log, false, null, $size - 512000);
        file_put_contents($log, $head . "\n[... " . round(($size - 1024000) / 1048576) . " MB of repetitive warnings cut ...]\n" . $tail);
    }
    $summary = sprintf('%d timestamp jumps, %d non-monotonic, %d corrupt-data warnings (log was %.0f MB, %d lines)',
        $n['jump'], $n['mono'], $n['corrupt'], $size / 1048576, $n['lines']);
    return [$summary, $last];
}

/** Last state from ffmpeg's -progress file: ['time' => 'HH:MM:SS', 'mtime' => unix] */
function parse_progress(string $f): array
{
    $txt = (string)@file_get_contents($f);
    $time = preg_match_all('/out_time=(\d+:\d\d:\d\d)/', $txt, $m) ? end($m[1]) : '';
    $secs = 0;
    if ($time !== '') { [$h, $mi, $se] = explode(':', $time); $secs = $h * 3600 + $mi * 60 + (int)$se; }
    return ['time' => $time, 'seconds' => $secs, 'mtime' => (int)@filemtime($f)];
}

/** True while the background ffmpeg for this folder is still working. */
function conversion_running(string $dir, array $job): bool
{
    if (file_exists("$dir/.convert_exit")) return false;                    // it reported back
    if (!empty($job['started']) && time() - (int)$job['started'] < 120) return true; // just started
    $pid = (int)@file_get_contents("$dir/.convert_pid");
    if ($pid > 0) {
        if (function_exists('posix_kill')) { if (@posix_kill($pid, 0)) return true; }
        elseif (@file_exists("/proc/$pid")) return true;
    }
    // Fallback: ffmpeg rewrites its progress file every second or so, and ends it with progress=end.
    $pf = "$dir/.convert_progress.txt";
    $pm = (int)@filemtime($pf);
    if ($pm <= 0) return false;
    $tail = (string)@file_get_contents($pf, false, null, max(0, (int)@filesize($pf) - 512));
    if (strpos($tail, 'progress=end') !== false) return time() - $pm < 5;
    return time() - $pm < 180;
}

/** Starts ffmpeg in the background, detached from this PHP run. */
function start_conversion(string $dir, int $attempt): bool
{
    $sources = video_sources($dir);
    if (!$sources) return false;
    $ff = find_ffmpeg();
    if (!$ff || !exec_available()) {
        logmsg('MP4 conversion skipped: ' . ($GLOBALS['ffmpegProblem'] ?? 'no ffmpeg'));
        return false;
    }
    $part = "$dir/video.part.mp4";
    $prog = "$dir/.convert_progress.txt";
    $list = "$dir/.concat.txt";
    // All parts joined end to end by ffmpeg's concat demuxer: each part keeps its own clean timeline.
    file_put_contents($list, implode("\n", array_map(function ($f) {
        return "file '" . str_replace("'", "'\\''", $f) . "'";
    }, $sources)) . "\n");
    foreach ([$part, $prog, "$dir/.convert_exit", "$dir/.convert_pid"] as $f) @unlink($f);

    $speed = CONVERT_SPEED > 0 && ffmpeg_has_readrate($ff) ? (float)CONVERT_SPEED : 0;
    if (CONVERT_SPEED > 0 && !$speed) logmsg('This ffmpeg version has no speed limit option (-readrate) - converting at full speed');
    // Lowest CPU and disk priority, so the website and recordings come first.
    $prefix = (@is_executable('/usr/bin/ionice') ? '/usr/bin/ionice -c3 ' : '')
        . (@is_executable('/usr/bin/nice') ? '/usr/bin/nice -n 19 ' : (@is_executable('/bin/nice') ? '/bin/nice -n 19 ' : ''));
    // -map v/a only: Twitch's extra metadata stream can't go into MP4.
    $ffcmd = $prefix . escapeshellarg($ff) . ' -hide_banner -loglevel warning -nostats -y -progress ' . escapeshellarg($prog)
        . ($speed ? ' -readrate ' . $speed : '')
        . ' -fflags +genpts -f concat -safe 0 -i ' . escapeshellarg($list)
        . ' -map 0:v? -map 0:a? -c copy -bsf:a aac_adtstoasc -avoid_negative_ts make_zero'
        . (CONVERT_FRAGMENTED ? ' -movflags +frag_keyframe+empty_moov+default_base_moof' : '')
        . ' ' . escapeshellarg($part) . ' 2> ' . escapeshellarg("$dir/conversion.log") . ' < /dev/null';
    // The wrapper notes its pid and, when ffmpeg ends, ffmpeg's exit code.
    $exitF = escapeshellarg("$dir/.convert_exit");
    $inner = 'echo $$ > ' . escapeshellarg("$dir/.convert_pid") . '; ' . $ffcmd
        . '; echo $? > ' . escapeshellarg("$dir/.convert_exit.tmp") . ' && mv ' . escapeshellarg("$dir/.convert_exit.tmp") . ' ' . $exitF;
    $setsid = @is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (@is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    @exec('nohup ' . $setsid . '/bin/sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &');

    logmsg('MP4 conversion started in the background: ' . basename($dir) . ($attempt > 1 ? " (attempt $attempt)" : '')
        . ($speed ? ' at max ' . $speed . 'x real-time speed' : ''));
    return true;
}

/** Whether this ffmpeg knows -readrate (checked once, remembered per ffmpeg file). */
function ffmpeg_has_readrate(string $ff): bool
{
    $cache = RECORDINGS_DIR . '/.ffmpeg_readrate.json';
    $id = $ff . '|' . (int)@filemtime($ff);
    $c = json_decode((string)@file_get_contents($cache), true) ?: [];
    if (($c['id'] ?? '') === $id) return (bool)$c['ok'];
    $out = [];
    @exec(escapeshellarg($ff) . ' -hide_banner -h long 2>&1 | grep -c -- -readrate', $out);
    $ok = (int)($out[0] ?? 0) > 0;
    @file_put_contents($cache, json_encode(['id' => $id, 'ok' => $ok]));
    return $ok;
}

/**
 * Checks what the background ffmpeg left behind and finishes the job.
 * @return string 'ok' | 'short' (MP4 made but shorter - both kept) | 'error' | 'killed' (stopped before it finished)
 */
function finish_conversion(string $dir, int $attempt, float $expected, int $started): string
{
    $sources = video_sources($dir);
    $mp4  = "$dir/video.mp4";
    $part = "$dir/video.part.mp4";
    $log  = "$dir/conversion.log";
    $prog = "$dir/.convert_progress.txt";
    $exitTxt = @file_get_contents("$dir/.convert_exit");
    $rc   = $exitTxt === false ? null : (int)trim($exitTxt);
    $p    = parse_progress($prog);
    $ended = strpos((string)@file_get_contents($prog, false, null, max(0, (int)@filesize($prog) - 2048)), 'progress=end') !== false;
    $mins = (int)round(((max($p['mtime'], (int)@filemtime("$dir/.convert_exit")) ?: time()) - $started) / 60);
    foreach (["$dir/.convert_exit", "$dir/.convert_pid", "$dir/.concat.txt"] as $f) @unlink($f);
    if (!$sources) { @unlink($prog); return 'ok'; }

    $ff     = find_ffmpeg();
    $tsSize = array_sum(array_map('filesize', $sources));
    $mpSize = (int)@filesize($part);
    $mpDur  = $mpSize > 0 && $ff ? media_duration($ff, $part) : 0.0;
    @unlink($prog);
    logmsg($rc === null
        ? sprintf('ffmpeg is gone after %d min without reporting back%s - checking what it left', $mins, $ended ? ' (it did reach the end)' : '')
        : sprintf('ffmpeg finished (exit %d) after %d min - checking the result', $rc, $mins));
    // Exit code lost (wrapper stopped) but ffmpeg wrote its end marker and the file is whole: count it as done.
    if ($rc === null && $ended && $mpDur > 0) $rc = 0;

    // What ffmpeg complained about, counted line by line: the log can be hundreds of MB.
    [$diag, $lastLines] = summarize_ffmpeg_log($log);

    if ($rc === 0 && $mpSize > $tsSize * 0.5 && $mpDur >= $expected * 0.9) {
        rename($part, $mp4);
        if (DELETE_TS_AFTER_CONVERT) {
            array_map('unlink', $sources);
            @rmdir("$dir/parts");
        }
        logmsg(sprintf('MP4 ready: video.mp4 (%s long, %d part(s), %.0f MB -> %.0f MB, took %d min)%s',
            gmdate('H:i:s', (int)$mpDur), count($sources), $tsSize / 1048576, $mpSize / 1048576, $mins,
            DELETE_TS_AFTER_CONVERT ? ', .ts deleted' : ''));
        @file_put_contents("$dir/summary.txt", "MP4 ready:        video.mp4\n", FILE_APPEND);
        return 'ok';
    }

    if ($rc === 0 && $mpDur > 0) {
        // Converting again gives the same result, so keep both and let a human look.
        rename($part, $mp4);
        $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
        $meta['mp4_incomplete'] = true;
        file_put_contents("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $msg = sprintf('MP4 is SHORTER than the recording (%s instead of ~%s) - kept both video.mp4 and the .ts file(s). ffmpeg: %s. Details: conversion.log',
            gmdate('H:i:s', (int)$mpDur), gmdate('H:i:s', (int)$expected), $diag);
        logmsg($msg);
        @file_put_contents("$dir/summary.txt", "MP4:              shorter than the recording - .ts file(s) kept too\n", FILE_APPEND);
        alert('MP4 shorter than recording: ' . basename($dir),
            "$msg\n\nFolder: $dir\nNothing is deleted. Check video.mp4 in the player; the .ts file(s) play in VLC.\n");
        return 'short';
    }

    @unlink($part);
    if ($rc === null || $rc === 137 || $rc === 143) {
        logmsg(sprintf('MP4 conversion attempt %d of %d was stopped after ~%d min, at %s of the video (stopped by the host, or STOP-CONVERSIONS.txt)',
            $attempt, CONVERT_ATTEMPTS, $mins, $p['time'] ?: 'an unknown point'));
        return 'killed';
    }
    logmsg(sprintf('MP4 conversion FAILED (exit %d, attempt %d of %d): %s | %s', $rc, $attempt, CONVERT_ATTEMPTS,
        $diag, implode(' | ', $lastLines) ?: 'no error output'));
    return 'error';
}

// ---------------------------------------------------------------- YouTube upload

/** YouTube account for this streamer (set on the dashboard), or null = no upload. */
function youtube_account(): ?string
{
    $c = channel_settings((string)($GLOBALS['channel'] ?? ''));
    if ($c === null) return youtube_account_env();
    $a = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($c['youtube'] ?? '')));
    return $a !== '' ? $a : null;
}

/** YOUTUBE=... from an old-style cron line, or null. */
function youtube_account_env(): ?string
{
    $a = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)getenv('YOUTUBE')));
    return $a !== '' ? $a : null;
}

/** Encrypt a secret with the REC_KEY passphrase. null if REC_KEY is missing. */
function secret_encrypt(string $plain): ?string
{
    $salt = random_bytes(16);
    $key  = secret_key($salt);
    if ($key === null) return null;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return json_encode(['v' => 1, 'salt' => base64_encode($salt), 'iv' => base64_encode($iv),
        'tag' => base64_encode($tag), 'ct' => base64_encode($ct)]);
}

function secret_decrypt(string $json): ?string
{
    $j   = json_decode($json, true) ?: [];
    $key = secret_key(base64_decode($j['salt'] ?? ''));
    if ($key === null) return null;
    $p = openssl_decrypt(base64_decode($j['ct'] ?? ''), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        base64_decode($j['iv'] ?? ''), base64_decode($j['tag'] ?? ''));
    return $p === false ? null : $p;
}

/** secret/youtube_setup_<account>.json (made by the PC tool) -> encrypted secret/youtube_<account>.enc */
function process_secret_setups(): void
{
    $dir = secret_dir();
    // Email was removed (alerts are on the dashboard): the stored mailbox password is no longer needed.
    foreach (['smtp.enc', 'smtp_setup.txt', 'test_email.txt'] as $old) {
        if (file_exists("$dir/$old")) { @unlink("$dir/$old"); logmsg("Removed secret/$old (email is no longer used)"); }
    }
    foreach (glob("$dir/youtube_setup_*.json") ?: [] as $f) {
        $acct = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', substr(basename($f, '.json'), 14)));
        $data = json_decode((string)file_get_contents($f), true);
        if ($acct === '' || empty($data['refresh_token']) || empty($data['client_id']) || empty($data['client_secret'])) {
            logmsg('Ignoring ' . basename($f) . ': not a valid account file from the PC tool');
            continue;
        }
        $enc = secret_encrypt(json_encode($data));
        if ($enc === null) {
            logmsg(basename($f) . ' found, but REC_KEY is missing (cron line / start script) - not stored yet');
            continue;
        }
        file_put_contents("$dir/youtube_$acct.enc", $enc);
        @chmod("$dir/youtube_$acct.enc", 0600);
        file_put_contents($f, str_repeat('x', (int)filesize($f)));
        unlink($f);
        @unlink("$dir/youtube_{$acct}_status.json");
        logmsg("YouTube account '$acct' (" . ($data['channel_title'] ?? '?') . ') stored encrypted - setup file deleted');
        // Uploads that were waiting for this account can go right away.
        if (file_exists(RECORDINGS_DIR . '/.upload_queue.json')) {
            queue_update(function (array &$q) use ($acct) {
                foreach ($q as &$job) {
                    if ($job['account'] === $acct) { $job['not_before'] = 0; $job['error'] = null; }
                }
            }, 'upload');
        }
    }
}

function youtube_credentials(string $acct): ?array
{
    $f = secret_dir() . "/youtube_$acct.enc";
    if (!file_exists($f)) return null;
    $p = secret_decrypt((string)file_get_contents($f));
    return $p === null ? null : json_decode($p, true);
}

/** Fresh access token for an account, or null (+ reason in $err). Cached per run. */
function youtube_access_token(string $acct, ?string &$err = null, bool $force = false): ?string
{
    static $cache = [];
    if (!$force && isset($cache[$acct]) && $cache[$acct]['exp'] > time() + 60) return $cache[$acct]['tok'];
    $c = youtube_credentials($acct);
    if (!$c) {
        $err = file_exists(secret_dir() . "/youtube_$acct.enc")
            ? 'account file could not be decrypted (REC_KEY missing or wrong)'
            : "no YouTube account '$acct' set up (secret/youtube_$acct.enc missing)";
        return null;
    }
    $code = 0;
    $body = http_request('https://oauth2.googleapis.com/token', http_build_query([
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
        'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token',
    ]), ['Content-Type: application/x-www-form-urlencoded'], 15, $code);
    $j = json_decode((string)$body, true) ?: [];
    if ($code !== 200 || empty($j['access_token'])) {
        $err = ($j['error'] ?? "HTTP $code") === 'invalid_grant'
            ? 'Google revoked or expired the sign-in (invalid_grant) - sign in again with the PC tool and re-export'
            : 'token refresh failed: ' . ($j['error_description'] ?? $j['error'] ?? "HTTP $code");
        return null;
    }
    $cache[$acct] = ['tok' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3600)];
    return $j['access_token'];
}

/** HTTP call returning [code, lowercased headers, body]. */
function http_full(string $method, string $url, array $headers, ?string $body = null, int $timeout = 120): array
{
    $resp = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$resp) {
            $p = strpos($h, ':');
            if ($p !== false) $resp[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            return strlen($h);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp, $out === false ? '' : $out];
}

function queue_upload(string $recDir, string $acct): void
{
    queue_update(function (array &$q) use ($recDir, $acct) {
        $q[$recDir] = $q[$recDir] ?? ['account' => $acct, 'tries' => 0, 'session' => null, 'offset' => 0,
            'size' => 0, 'file' => null, 'queued' => time(), 'not_before' => 0, 'error' => null];
    }, 'upload');
}

/** Uploads queued recordings for up to UPLOAD_BUDGET seconds; resumes next run. */
function process_uploads(): void
{
    if (!file_exists(RECORDINGS_DIR . '/.upload_queue.json')) return;
    if (any_channel_recording()) return; // reading big files counts towards the host's IO limit
    // A recording is being downloaded to a PC (the player touches this file): both read big files and
    // share the host's IO limit, so the upload waits until the download is done (or paused).
    $dl = RECORDINGS_DIR . '/.pc_download';
    if (is_file($dl) && time() - (int)filemtime($dl) < 90) {
        if (!is_file("$dl.noted")) { logmsg('YouTube upload waits: a recording is being downloaded to a PC (they share the IO limit)'); @touch("$dl.noted"); }
        return;
    }
    @unlink("$dl.noted");
    $lk = fopen(RECORDINGS_DIR . '/.upload.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;

    $ownLog = $GLOBALS['logFile'];
    $deadline = microtime(true) + UPLOAD_BUDGET;
    while (microtime(true) < $deadline) {
        $job = null;
        queue_update(function (array &$q) use (&$job) {
            foreach ($q as $dir => $j) {
                if (($j['not_before'] ?? 0) > time()) continue;
                if (function_exists('scan_holds_upload') && scan_holds_upload($dir)) continue; // waits for the PC scan
                $job = [$dir, $j]; break;
            }
        }, 'upload');
        if (!$job) break;
        [$dir, $j] = $job;
        $GLOBALS['logFile'] = dirname($dir) . '/recorder.log';
        $result = upload_step($dir, $j, $deadline); // updates $j
        queue_update(function (array &$q) use ($dir, $j, $result) {
            if ($result === 'done' || $result === 'failed') unset($q[$dir]);
            elseif (isset($q[$dir])) $q[$dir] = $j;
        }, 'upload');
        if ($result !== 'done') break; // paused, failed or out of time: leave the rest for later
    }
    $GLOBALS['logFile'] = $ownLog;
    flock($lk, LOCK_UN);
}

/** @return string 'done' | 'progress' | 'paused' | 'failed' */
function upload_step(string $dir, array &$j, float $deadline): string
{
    $acct = $j['account'];
    $pause = function (string $why, int $secs) use (&$j, $dir, $acct) {
        $j['error'] = $why;
        $j['not_before'] = time() + $secs;
        $st = secret_dir() . "/youtube_{$acct}_status.json";
        $s = file_exists($st) ? (json_decode((string)file_get_contents($st), true) ?: []) : [];
        if (($s['error'] ?? '') !== $why || time() - ($s['alerted'] ?? 0) > 86400) {
            logmsg("YouTube upload paused: $why");
            alert("YouTube upload problem ($acct)", "Uploading " . basename($dir) . " to YouTube account '$acct' is paused:\n\n$why\n\nIt retries automatically; nothing is lost.\n");
            $s['alerted'] = time();
        }
        $s['error'] = $why;
        file_put_contents($st, json_encode($s));
        return 'paused';
    };
    $fail = function (string $why) use (&$j, $dir, $acct) {
        logmsg("YouTube upload FAILED: $why");
        alert("YouTube upload failed: " . basename($dir), "Uploading to account '$acct' failed and was stopped:\n\n$why\n\nThe recording is still on the server.\n");
        return 'failed';
    };

    if (!$j['file']) {
        $m = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
        $useTs = !empty($m['mp4_incomplete']) && file_exists("$dir/video.ts"); // the complete one
        $file = (!$useTs && file_exists("$dir/video.mp4")) ? "$dir/video.mp4" : (file_exists("$dir/video.ts") ? "$dir/video.ts" : null);
        if (!$file) return $fail('no video file in ' . basename($dir));
        $j['file'] = $file;
        $j['size'] = (int)filesize($file);
    }
    if (!file_exists($j['file'])) return $fail('video file disappeared: ' . basename($j['file']));

    $err = null;
    $tok = youtube_access_token($acct, $err);
    if (!$tok) return $pause($err, 3600);

    $total = $j['size'];
    $mime  = substr($j['file'], -4) === '.mp4' ? 'video/mp4' : 'video/mp2t';

    // 1. Start a resumable upload session.
    if (!$j['session']) {
        $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
        $clean = fn($t) => str_replace(['<', '>'], '', (string)$t);
        $date  = substr((string)($meta['stream_started'] ?? date('Y-m-d')), 0, 10);
        $mo    = apply_moments($dir); // rules may have changed since the stream: scan again now
        $base  = $clean(($meta['channel'] ?? basename(dirname($dir))) . ' - ' . (($meta['title'] ?? '') !== '' ? $meta['title'] : 'stream') . " ($date)");
        $title = function_exists('moments_title') ? moments_title($base, array_map($clean, $mo['labels'] ?? []))
            : (strlen($base) > 100 ? substr($base, 0, 97) . '...' : $base);
        if ($mo && $mo['moments']) logmsg(sprintf('YouTube moments for %s: %d found%s', basename($dir), count($mo['moments']),
            $mo['labels'] ? ' - title gets: ' . implode(', ', $mo['labels']) : ''));
        $desc  = $clean(youtube_description($meta, $mo ? $mo['moments'] : []));
        $desc  = function_exists('mb_strcut') ? mb_strcut($desc, 0, 4900) : substr($desc, 0, 4900);
        [$code, $h, $body] = http_full('POST',
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
            ["Authorization: Bearer $tok", 'Content-Type: application/json; charset=UTF-8',
             "X-Upload-Content-Length: $total", "X-Upload-Content-Type: $mime"],
            json_encode([
                'snippet' => ['title' => $title, 'description' => $desc, 'categoryId' => YOUTUBE_CATEGORY],
                'status'  => ['privacyStatus' => YOUTUBE_PRIVACY, 'selfDeclaredMadeForKids' => false],
            ]), 30);
        if ($code === 200 && !empty($h['location'])) {
            $j['session'] = $h['location'];
            $j['offset']  = 0;
            $j['error']   = null;
            logmsg(sprintf("YouTube upload started: %s (%.0f MB) to account '%s'", basename($dir), $total / 1048576, $acct));
        } else {
            return handle_yt_error($code, $body, $j, $pause, $fail);
        }
    } else {
        // Resuming: ask YouTube how much it already has.
        [$code, $h, $body] = http_full('PUT', $j['session'], ["Authorization: Bearer $tok", "Content-Range: bytes */$total", 'Content-Length: 0'], '', 30);
        if ($code === 200 || $code === 201) {
            return finish_upload($dir, $j, $body);
        } elseif ($code === 308) {
            $j['offset'] = isset($h['range']) && preg_match('/-(\d+)$/', $h['range'], $m) ? (int)$m[1] + 1 : 0;
        } elseif ($code === 404 || $code === 410) {
            logmsg('YouTube upload session expired - starting the upload again');
            $j['session'] = null;
            $j['offset'] = 0;
            return 'progress';
        } else {
            return handle_yt_error($code, $body, $j, $pause, $fail);
        }
    }

    // 2. Send chunks until time runs out.
    $chunk = UPLOAD_CHUNK_MB * 1048576; // multiple of 256 KB as required
    $fh = fopen($j['file'], 'rb');
    while ($j['offset'] < $total && microtime(true) < $deadline) {
        fseek($fh, $j['offset']);
        $data = fread($fh, $chunk);
        $end  = $j['offset'] + strlen($data) - 1;
        [$code, $h, $body] = http_full('PUT', $j['session'],
            ["Authorization: Bearer $tok", "Content-Range: bytes {$j['offset']}-$end/$total", 'Content-Type: ' . $mime], $data, 300);
        if ($code === 308) {
            $j['offset'] = isset($h['range']) && preg_match('/-(\d+)$/', $h['range'], $m) ? (int)$m[1] + 1 : 0;
            $j['error'] = null;
        } elseif ($code === 200 || $code === 201) {
            fclose($fh);
            return finish_upload($dir, $j, $body);
        } elseif ($code === 401) {
            $tok = youtube_access_token($acct, $err, true);
            if (!$tok) { fclose($fh); return $pause($err, 3600); }
        } else {
            fclose($fh);
            return handle_yt_error($code, $body, $j, $pause, $fail);
        }
    }
    fclose($fh);
    if (DEBUG) logmsg(sprintf('YouTube upload: %.0f%% (%.0f / %.0f MB)', 100 * $j['offset'] / max(1, $total), $j['offset'] / 1048576, $total / 1048576));
    return 'progress';
}

function handle_yt_error(int $code, string $body, array &$j, callable $pause, callable $fail): string
{
    $e = json_decode($body, true)['error'] ?? [];
    $reason = $e['errors'][0]['reason'] ?? ($e['status'] ?? '');
    $msg = "HTTP $code" . ($reason ? " $reason" : '') . (!empty($e['message']) ? ': ' . $e['message'] : '');
    if ($code === 0 || $code >= 500 || $code === 429) {
        $j['error'] = $msg;
        $j['not_before'] = time() + 120; // network/Google hiccup: retry soon
        logmsg("YouTube upload: temporary problem ($msg), retrying in 2 minutes");
        return 'paused';
    }
    if ($reason === 'uploadLimitExceeded') {
        return $pause("YouTube CHANNEL daily upload limit reached ($msg). New or unverified channels have a low limit - verify the channel at https://www.youtube.com/verify. Retrying in 24 hours", 24 * 3600);
    }
    if ($reason === 'quotaExceeded') {
        return $pause("Google PROJECT daily upload quota used up ($msg) - resets at midnight Pacific time (09:00 NL). Retrying later", 6 * 3600);
    }
    if ($reason === 'rateLimitExceeded') {
        $j['error'] = $msg;
        $j['not_before'] = time() + 900;
        logmsg("YouTube upload: too many requests ($msg), retrying in 15 minutes");
        return 'paused';
    }
    if ($code === 401 || $code === 403) {
        return $pause($msg, 3600);
    }
    if (++$j['tries'] >= 3) return $fail($msg);
    $j['session'] = null;
    $j['offset'] = 0;
    $j['not_before'] = time() + 600;
    logmsg("YouTube upload error ($msg) - will start over in 10 minutes (attempt {$j['tries']}/3)");
    return 'paused';
}

/**
 * The public YouTube description: only what viewers should see (title, categories, date, length,
 * moments). The internal summary (server folder, cron runs, gaps) stays in the recording folder.
 */
function youtube_description(array $meta, array $moments): string
{
    $clock = function (float $t) { $t = (int)max(0, $t); return sprintf('%d:%02d:%02d', intdiv($t, 3600), intdiv($t, 60) % 60, $t % 60); };
    $who = (string)($meta['channel'] ?? 'The streamer');
    $start = strtotime((string)($meta['stream_started'] ?? '')) ?: time();
    $lines = [sprintf('%s streamed on Twitch on %s.', $who, date('j M Y, H:i', $start))];
    if (($meta['title'] ?? '') !== '') $lines[] = 'Title: ' . $meta['title'];
    if (!empty($meta['recorded_seconds'])) $lines[] = 'Length: ' . $clock((float)$meta['recorded_seconds']);
    $changes = $meta['changes'] ?? [];
    $games = [];
    foreach ($changes as $c) {
        $g = (string)($c['game'] ?? '');
        if ($g !== '' && (!$games || end($games)[1] !== $g)) $games[] = [(float)($c['video'] ?? 0), $g];
    }
    if (count($games) > 1) {
        $lines[] = '';
        $lines[] = 'Categories:';
        foreach ($games as [$t, $g]) $lines[] = $clock($t) . ' ' . $g;
    } elseif (($meta['game'] ?? '') !== '') {
        $lines[] = 'Category: ' . $meta['game'];
    }
    $out = implode("\n", $lines) . "\n\n";
    if ($moments && function_exists('moments_description')) $out .= moments_description($moments);
    return $out . "Recorded with Stream Recorder\u{2122}\n";
}

function finish_upload(string $dir, array $j, string $body): string
{
    $v  = json_decode($body, true) ?: [];
    $id = (string)($v['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) $id = ''; // only YouTube's own video id format
    $privacy = in_array($v['status']['privacyStatus'] ?? '', ['private', 'unlisted', 'public'], true) ? $v['status']['privacyStatus'] : YOUTUBE_PRIVACY;
    $url = $id !== '' ? "https://youtu.be/$id" : '';
    $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
    $meta['youtube'] = ['id' => $id, 'url' => $url, 'account' => $j['account'], 'privacy' => $privacy, 'uploaded' => date('Y-m-d H:i:s')];
    file_put_contents("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @file_put_contents("$dir/summary.txt", "YouTube:          " . ($url ?: '(no video id returned)') . " ($privacy, account '{$j['account']}')\n", FILE_APPEND);
    logmsg("YouTube upload DONE: " . ($url ?: '(no video id returned)') . " ($privacy)");
    alert('Uploaded to YouTube: ' . (($meta['title'] ?? '') !== '' ? $meta['title'] : basename($dir)),
        "The recording was uploaded to YouTube account '{$j['account']}' as $privacy:\n\n$url\n\nThe recording stays on the server.\n", 'success');
    return 'done';
}

// ---------------------------------------------------------------- recording metadata (used by player + dashboard)

function local_time_ms(float $t): string
{
    return date('Y-m-d H:i:s', (int)floor($t)) . sprintf('.%03d', (int)(fmod($t, 1) * 1000));
}

function write_meta(string $recDir, array $s, string $display, array $extra = []): void
{
    $f = "$recDir/meta.json";
    $old = file_exists($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $meta = array_merge($old, [
        'channel'        => $display,
        'platform'       => platform_of(basename(dirname($recDir))),
        'title'          => $s['title'] ?? '',
        'game'           => $s['game'] ?? '',
        'stream_started' => isset($s['started_at']) ? date('Y-m-d H:i:s', strtotime($s['started_at'])) : null,
        // Same local time format as chat.txt, so the player can line them up.
        'video_start'    => !empty($s['video_start']) ? local_time_ms((float)$s['video_start']) : null,
        'timeline'       => $s['timeline'] ?? null, // [[video seconds, local time], ...] at each part start
        'changes'        => $s['changes'] ?? [],    // title/category changes during the stream
        'timezone'       => date_default_timezone_get(),
    ], $extra);
    file_put_contents($f, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Puts the player's sync info as one line at the top of chat.txt ("#META {...}"), so video + chat.txt
 * are enough for the player. Chat lines always start with "[", so this line never looks like chat.
 */
function embed_meta_in_chat(string $recDir): void
{
    $chatF = "$recDir/chat.txt";
    $meta = json_decode((string)@file_get_contents("$recDir/meta.json"), true) ?: [];
    if (!file_exists($chatF) || !$meta) return;
    $keep = array_intersect_key($meta, array_flip(['channel', 'title', 'game', 'stream_started', 'stream_ended', 'video_start', 'timeline', 'timezone', 'changes']));
    $chat = (string)file_get_contents($chatF);
    $chat = preg_replace('/\A#META [^\n]*\n/', '', $chat); // replace an older header
    file_put_contents("$chatF.tmp", '#META ' . json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $chat);
    rename("$chatF.tmp", $chatF);
}

// ---------------------------------------------------------------- YouTube "moments"
/** Scan a recording with the dashboard's moment rules and store the result in its meta.json. */
function apply_moments(string $dir): ?array
{
    if (!function_exists('moments_scan') || !is_file(MOMENTS_FILE)) return null;
    $rules = moments_rules(MOMENTS_FILE);
    if (!$rules) return null;
    $res = moments_scan($dir, $rules, basename(dirname($dir)));
    $f = "$dir/meta.json";
    $meta = json_decode((string)@file_get_contents($f), true) ?: [];
    $meta['moments'] = $res['moments'];
    $meta['moment_labels'] = $res['labels'];
    $meta['moments_checked'] = $res['checked'];
    file_put_contents($f, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res;
}

// ---------------------------------------------------------------- streamer list (channels.json) + manager

/** The streamer list: ['login' => ['enabled' => bool, 'youtube' => '', 'twitch_auth' => 'auto|on|off']]. */
function channels_load(bool $fresh = false): array
{
    static $cache = null, $mtime = -1, $checked = 0.0;
    if (!$fresh && $cache !== null && microtime(true) - $checked < 2) return $cache;
    $checked = microtime(true);
    clearstatcache(true, CHANNELS_FILE);
    $m = (int)@filemtime(CHANNELS_FILE);
    if ($cache !== null && $m === $mtime) return $cache;
    $raw = @file_get_contents(CHANNELS_FILE);
    $list = $raw === false ? [] : json_decode($raw, true);
    if (!is_array($list)) return $cache ?? []; // unreadable: keep the last good copy
    $cache = $list;
    $mtime = $m;
    return $cache;
}

/** This streamer's settings, or null if it isn't on the list. */
function channel_settings(string $ch): ?array
{
    $list = channels_load();
    return isset($list[$ch]) && is_array($list[$ch]) ? $list[$ch] : null;
}

/** Switched on? Streamers not on the list (no channels.json yet) count as on. */
function channel_enabled(string $ch): bool
{
    $c = channel_settings($ch);
    return $c === null || !empty($c['enabled']);
}

/** Locked read-modify-write of channels.json (the dashboard does the same), written atomically. */
function channels_update(callable $fn): void
{
    $lk = fopen(CHANNELS_FILE . '.lock', 'c');
    if (!$lk) return;
    flock($lk, LOCK_EX);
    $list = json_decode((string)@file_get_contents(CHANNELS_FILE), true);
    if (!is_array($list)) $list = [];
    $fn($list);
    file_put_contents(CHANNELS_FILE . '.tmp', json_encode($list, JSON_PRETTY_PRINT));
    rename(CHANNELS_FILE . '.tmp', CHANNELS_FILE);
    flock($lk, LOCK_UN);
    fclose($lk);
    channels_load(true);
}

/** Display name for a recording (Twitch's spelling), for when the stream info isn't at hand. */
function display_name(array $state, string $chanDir, string $fallback): string
{
    if (!empty($state['display'])) return $state['display'];
    $m = json_decode((string)@file_get_contents($chanDir . '/' . ($state['dir'] ?? '') . '/meta.json'), true);
    return is_array($m) && !empty($m['channel']) ? $m['channel'] : $fallback;
}

/**
 * Emergency brake: a file named STOP-CONVERSIONS.txt in the recorder folder stops any running
 * conversion, empties the conversion queue and prevents new conversions while it exists.
 */
function stop_conversions_check(): void
{
    if (!file_exists(__DIR__ . '/STOP-CONVERSIONS.txt')) return;
    if (exec_available()) @exec('pkill -u ' . escapeshellarg((string)get_current_user()) . ' -f ' . escapeshellarg('ffmpeg.*video\.part\.mp4') . ' 2>/dev/null');
    if (file_exists(RECORDINGS_DIR . '/.convert_queue.json')) {
        @unlink(RECORDINGS_DIR . '/.convert_queue.json');
        logmsg('STOP-CONVERSIONS.txt: stopped running conversions and emptied the queue');
    }
}

/** Starts "php record.php <args>" in the background, detached from this run. */
function spawn_php(array $args): void
{
    $setsid = @is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (@is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    @exec('nohup ' . $setsid . escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg(__FILE__) . ' '
        . implode(' ', array_map('escapeshellarg', $args))
        . ' >> ' . escapeshellarg(__DIR__ . '/cron_output.txt') . ' 2>&1 < /dev/null &');
}

/** record.php without a streamer name, every minute from cron. */
function run_manager(): void
{
    $GLOBALS['logFile']   = RECORDINGS_DIR . '/.system.log';
    $GLOBALS['stateFile'] = RECORDINGS_DIR . '/.system_state.json';
    if (@filesize($GLOBALS['logFile']) > 2 * 1048576) {
        $keep = (string)file_get_contents($GLOBALS['logFile'], false, null, (int)filesize($GLOBALS['logFile']) - 512000);
        file_put_contents($GLOBALS['logFile'], substr($keep, (int)strpos($keep, "\n") + 1));
    }
    // For the dashboard: settings it shows or needs (it can't read config.php itself).
    @file_put_contents(RECORDINGS_DIR . '/.settings.json', json_encode(['timezone' => date_default_timezone_get(),
        'quality' => QUALITY, 'convert_speed' => CONVERT_SPEED, 'php' => PHP_VERSION, 'updated' => time()]));
    if (!exec_available()) {
        $st = load_state();
        if (empty($st['exec_alerted'])) {
            alert('Recorder cannot start recordings', "PHP's exec() is disabled for the cron PHP, so the manager can't start recording runs.\nUse one cron line per streamer instead (see README).\n");
            save_state(['exec_alerted' => true]);
        }
        return;
    }
    if (!file_exists(CHANNELS_FILE)) {
        channels_update(function (array &$l) {}); // empty list; add streamers on the dashboard
        logmsg('Created channels.json - add streamers on the dashboard');
    }
    foreach (channels_load(true) as $ch => $c) {
        if (!is_string($ch) || (platform_parse($ch)['key'] ?? null) !== $ch) continue;
        $st = json_decode((string)@file_get_contents(RECORDINGS_DIR . "/$ch/state.json"), true) ?: [];
        // Switched on: record. Switched off with a recording still open: wrap it up.
        if (!empty($c['enabled']) || !empty($st['stream_id'])) spawn_php([$ch]);
    }
    spawn_php(['--io-sampler']);

    $lk = fopen(RECORDINGS_DIR . '/.manager.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return; // the previous one is still uploading
    stop_conversions_check();
    manager_checks();
    process_secret_setups();
    check_token_health();
    process_conversions();
    if (function_exists('process_scans')) process_scans();
    if (function_exists('process_speech_lib')) process_speech_lib();
    if (function_exists('process_summaries')) process_summaries();
    process_uploads();
    process_storage_rules();
    flock($lk, LOCK_UN);
}

/** Is a recording in use right now (recording, converting, uploading, scanning, being summarised)? */
function recording_in_use(string $d): bool
{
    $ch = basename(dirname($d));
    $st = json_decode((string)@file_get_contents(RECORDINGS_DIR . "/$ch/state.json"), true) ?: [];
    if (!empty($st['stream_id']) && ($st['dir'] ?? '') === basename($d)) return true;
    foreach (['.convert_queue.json', '.upload_queue.json'] as $q) {
        $j = json_decode((string)@file_get_contents(RECORDINGS_DIR . "/$q"), true) ?: [];
        if (isset($j[$d]) || isset($j[RECORDINGS_DIR . "/$ch/" . basename($d)])) return true;
    }
    if (function_exists('scan_load') && in_array(scan_load($d)['state'] ?? '', ['ask', 'requested', 'preparing', 'ready', 'scanning'], true)) return true;
    if (function_exists('summary_load') && in_array(summary_load($d)['state'] ?? '', SUMMARY_ACTIVE, true)) return true;
    return false;
}

/** Hourly in the manager: each streamer's storage rule deletes old footage (the text stays). */
function process_storage_rules(): void
{
    $f = RECORDINGS_DIR . '/.storage_rules.json';
    $last = (int)(json_decode((string)@file_get_contents($f), true)['checked'] ?? 0);
    if (time() - $last < 3600) return;
    @file_put_contents($f, json_encode(['checked' => time()]));
    foreach (channels_load(true) as $ch => $c) {
        if (!is_string($ch) || !is_array($c) || empty($c['storage']) || !preg_match(STREAMER_KEY_RE, $ch)) continue;
        $chanDir = RECORDINGS_DIR . "/$ch";
        foreach (storage_plan($chanDir, (array)$c['storage'], 'recording_in_use') as $d => $why) {
            $freed = delete_footage($d, "storage rule: $why");
            $line = $freed === null ? 'Storage rule: could not delete all footage of ' . basename($d)
                : sprintf('Storage rule (%s): video of %s deleted, %s freed - chat, transcript and summary are kept', $why, basename($d), size_text($freed));
            @file_put_contents("$chanDir/recorder.log", '[' . date('Y-m-d H:i:s') . "] $line\n", FILE_APPEND | LOCK_EX);
        }
    }
}

/** Once a minute in the manager: early disk warning, and a warning when this folder is reachable from the web. */
function manager_checks(): void
{
    $f = RECORDINGS_DIR . '/.checks.json';
    $c = json_decode((string)@file_get_contents($f), true) ?: [];
    $changed = false;
    if (MIN_FREE_GB > 0) {
        $free = free_space_bytes();
        if ($free !== null && $free < 2 * MIN_FREE_GB * 1073741824 && time() - (int)($c['disk_warned'] ?? 0) > 86400) {
            $low = $free < MIN_FREE_GB * 1073741824;
            alert($low ? 'Disk almost full - recording is paused' : 'Disk space getting low',
                sprintf("Only %.1f GB is free. Recording pauses below %s GB (MIN_FREE_GB) and continues by itself when there is room again.\n\nDelete recordings you no longer need on the dashboard.\n",
                    $free / 1073741824, MIN_FREE_GB), 'warning');
            $c['disk_warned'] = time();
            $changed = true;
        }
    }
    // A recorder folder inside the website's folder could be downloadable (.htaccess only protects on Apache/LiteSpeed).
    $inWeb = (bool)preg_match('#/(public_html|www|wwwroot|htdocs|httpdocs|html)/#i', str_replace('\\', '/', __DIR__) . '/');
    if ($inWeb && empty($c['webroot_warned'])) {
        alert('Recorder folder is inside the website folder',
            "This recorder runs from " . __DIR__ . ", which looks like part of your website.\n\n"
            . "Recordings and the secret/ folder could then be downloaded from the web on servers that ignore .htaccess (e.g. nginx).\n"
            . "Move the folder next to public_html instead of inside it, and update the cron line and RECORDER_DIR (docs/install-webserver.md).\n", 'warning');
        $c['webroot_warned'] = time();
        $changed = true;
    }
    if (!$inWeb && !empty($c['webroot_warned'])) { unset($c['webroot_warned']); $changed = true; }
    if ($changed) @file_put_contents($f, json_encode($c));
}

// ---------------------------------------------------------------- IO usage (dashboard graph)
// Every 5 s the sampler reads how much each of this account's processes read from and wrote to
// disk (/proc/<pid>/io, the same counters the host's IO limit is based on) and adds the difference
// to a per-minute history for the last 24 hours: recordings/.io_history.json.


function io_sampler_main(): void
{
    $GLOBALS['logFile'] = RECORDINGS_DIR . '/.system.log';
    $lk = fopen(RECORDINGS_DIR . '/.io_sampler.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return; // the previous one is still sampling
    $end = time() + 54;
    while (true) {
        io_sample();
        if (time() + IO_SAMPLE_INTERVAL > $end) break;
        sleep(IO_SAMPLE_INTERVAL);
    }
    flock($lk, LOCK_UN);
}

function io_sample(): void
{
    if (!is_dir('/proc/self')) return;
    $stateF = RECORDINGS_DIR . '/.io_pids.json';
    $prev = json_decode((string)@file_get_contents($stateF), true) ?: [];
    $prevT = (float)($prev['t'] ?? 0);
    $prevP = $prev['p'] ?? [];
    $now = microtime(true);
    $fresh = $now - $prevT < 30; // previous sample is recent: processes started since then count in full
    $btime = 0;
    if (preg_match('/^btime (\d+)/m', (string)@file_get_contents('/proc/stat'), $m)) $btime = (int)$m[1];
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $add = ['r' => 0, 'w' => 0, 'convert' => 0, 'record' => 0, 'other' => 0];
    $seen = [];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $pd) {
        if (@fileowner($pd) !== $uid) continue;
        $io = @file_get_contents("$pd/io");
        $stat = @file_get_contents("$pd/stat");
        if ($io === false || $stat === false) continue;
        if (!preg_match('/^read_bytes: (\d+)/m', $io, $mr) || !preg_match('/^write_bytes: (\d+)/m', $io, $mw)) continue;
        $f = explode(' ', substr($stat, (int)strrpos($stat, ')') + 2));
        $startTicks = (int)($f[19] ?? 0);
        $key = basename($pd) . ':' . $startTicks;
        $r = (int)$mr[1];
        $w = (int)$mw[1];
        if (isset($prevP[$key])) {
            $dr = max(0, $r - $prevP[$key][0]);
            $dw = max(0, $w - $prevP[$key][1]);
        } elseif ($fresh && $btime && $btime + $startTicks / 100 >= $prevT - 1) {
            $dr = $r; $dw = $w; // started since the last sample
        } else {
            $dr = $dw = 0;      // first sight of an older process: only a baseline
        }
        $seen[$key] = [$r, $w];
        if ($dr + $dw === 0) continue;
        $cmd = str_replace("\0", ' ', (string)@file_get_contents("$pd/cmdline"));
        $kind = stripos($cmd, 'ffmpeg') !== false ? 'convert'
            : (strpos($cmd, 'record.php') !== false && strpos($cmd, '--io-sampler') === false ? 'record' : 'other');
        $add['r'] += $dr;
        $add['w'] += $dw;
        $add[$kind] += $dr + $dw;
    }
    file_put_contents($stateF . '.tmp', json_encode(['t' => $now, 'p' => $seen]));
    rename($stateF . '.tmp', $stateF);

    $hf = RECORDINGS_DIR . '/.io_history.json';
    $h = fopen($hf, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $hist = json_decode((string)stream_get_contents($h), true) ?: [];
    $minute = (string)(intdiv((int)$now, 60) * 60);
    $row = $hist[$minute] ?? [0, 0, 0, 0, 0, 0]; // read, write, convert, record, other, samples
    $hist[$minute] = [$row[0] + $add['r'], $row[1] + $add['w'], $row[2] + $add['convert'],
        $row[3] + $add['record'], $row[4] + $add['other'], $row[5] + 1];
    $cut = (int)$minute - 86400;
    foreach (array_keys($hist) as $k) if ((int)$k < $cut) unset($hist[$k]);
    ksort($hist);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($hist));
    flock($h, LOCK_UN);
    fclose($h);
}

// ---------------------------------------------------------------- end of stream

function finalize(array $s, string $display, string $chanDir): void
{
    $recDir  = $chanDir . '/' . $s['dir'];
    $wall    = time() - strtotime($s['started_at']);
    @unlink($chanDir . '/.playlist.json');      // belongs to the finished stream
    @unlink($chanDir . '/.auth_decision.json'); // re-check ad-free status next stream (sub may have changed)
    $summary = implode("\n", [
        "Channel:          $display",
        "Title:            {$s['title']}",
        "Category:         {$s['game']}",
        ...array_map(function ($c) {
            return sprintf('Changed at %s:  "%s" (%s)', gmdate('H:i:s', (int)$c['video']), $c['title'], $c['game']);
        }, $s['changes'] ?? []),
        'Started:          ' . date('Y-m-d H:i', strtotime($s['started_at'])),
        'Ended (approx):   ' . date('Y-m-d H:i'),
        'Stream length:    ' . gmdate('H:i:s', max(0, $wall)) . ' (approx.)',
        'Recorded video:   ' . gmdate('H:i:s', (int)$s['seconds']),
        sprintf('File size:        %.1f MB', $s['bytes'] / 1048576),
        "Gaps:             {$s['gaps']} ({$s['missing_segments']} segments missing)",
        "Ads skipped:      {$s['ads_skipped']} segments",
        "Chat lines:       {$s['chat_lines']}",
        "Cron runs:        {$s['runs']} (longest {$s['longest_run']}s, killed by host: {$s['kills']}x)",
        ...(!empty($s['capped']) ? ['Length cap:       stopped at ' . MAX_RECORDING_HOURS . ' h (MAX_RECORDING_HOURS)'] : []),
        ...(!empty($s['disk_alerted']) ? ['Disk:             paused at least once because free space was below ' . MIN_FREE_GB . ' GB'] : []),
        "Folder:           $recDir",
    ]) . "\n";

    $yt = youtube_account();
    $scanAsked = function_exists('scan_after_stream') && scan_after_stream($recDir, channel_settings((string)($GLOBALS['channel'] ?? '')));
    $converting = false;
    if (AUTO_CONVERT && video_sources($recDir)) {
        if (find_ffmpeg()) {
            queue_conversion($recDir, (float)$s['seconds'], $yt);
            $converting = true;
            $summary .= "MP4:              converting now - video.mp4 will appear in the folder"
                . (DELETE_TS_AFTER_CONVERT ? ' and replace video.ts' : '') . "\n";
        } else {
            $summary .= 'MP4:              not available - ' . ($GLOBALS['ffmpegProblem'] ?? 'no ffmpeg found') . "\n";
        }
    }
    if ($yt) {
        if (!$converting) queue_upload($recDir, $yt); // otherwise queued once conversion is done
        $summary .= "YouTube:          will be uploaded (private) to account '$yt'" . ($converting ? ' after MP4 conversion' : '')
            . ($scanAsked ? ', after your PC-scan choice (at most ' . SCAN_WAIT_HOURS . ' h)' : '') . "\n";
    }
    if ($scanAsked) $summary .= "PC scan:          choose Scan or Skip on the dashboard (subtitles, translation, spoken moments)\n";
    if (function_exists('summary_after_stream') && summary_after_stream($recDir)) {
        $summary .= "AI summary:       written by Claude" . ($scanAsked ? ' after your PC-scan choice' : '') . " - it appears on the dashboard\n";
    }

    write_meta($recDir, $s, $display, ['stream_ended' => date('Y-m-d H:i:s'), 'recorded_seconds' => round((float)$s['seconds'])]);
    embed_meta_in_chat($recDir);
    $mo = apply_moments($recDir);
    if ($mo && $mo['moments']) {
        $summary .= "\nMoments found:    " . count($mo['moments']) . ($mo['labels'] ? ' (' . implode(', ', $mo['labels']) . ')' : '');
    }
    @file_put_contents($recDir . '/summary.txt', $summary);
    logmsg("ENDED: stream finished.\n" . $summary);

    alert("$display streamed: " . (($s['title'] ?? '') !== '' ? $s['title'] : '(no title)'), "A stream was recorded. Decide whether to keep it:\n\n$summary", 'info');
}

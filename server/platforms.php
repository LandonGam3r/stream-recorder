<?php
/**
 * Platforms: how the recorder talks to Twitch, Kick and YouTube Live (required by record.php; the dashboard
 * uses platform_parse() and the access test).
 *
 * A streamer key in channels.json is also its folder name in recordings/, and names the platform:
 *   "somename"            Twitch (as before, so existing lists and folders keep working)
 *   "kick-somename"       Kick
 *   "youtube-somehandle"  YouTube Live (the @handle without @, or a channel id starting with UC)
 * Twitch names can't contain "-", so the keys never clash. Everything after the live check is shared:
 * the video is HLS on all three (parts/, chat.txt, meta.json), so conversion, the player, the scan,
 * summaries and uploads work the same for every platform.
 */

const PLATFORMS = ['twitch' => 'Twitch', 'kick' => 'Kick', 'youtube' => 'YouTube'];
const PLATFORM_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
/** Any streamer key / folder name (used to check input everywhere). */
const STREAMER_KEY_RE = '/^(?:[a-z0-9_]{1,40}|kick-[a-z0-9_-]{2,25}|youtube-(?:UC[A-Za-z0-9_-]{22}|[a-z0-9._-]{3,30}))$/';

/**
 * "kick-Name", "kick:Name" -> ['platform' => 'kick', 'name' => 'name', 'key' => 'kick-name', 'label' => 'Kick'], or null.
 */
function platform_parse(string $key): ?array
{
    $key = trim($key);
    $p = 'twitch';
    if (preg_match('/^(kick|youtube|yt|twitch)[:-](.*)$/i', $key, $m)) {
        $p = strtolower($m[1]) === 'yt' ? 'youtube' : strtolower($m[1]);
        $key = $m[2];
    }
    if ($p === 'youtube') {
        $name = ltrim($key, '@');
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $name)) {
            // a channel id: case matters
        } elseif (preg_match('/^[A-Za-z0-9._-]{3,30}$/', $name) && trim($name, '.') === $name) {
            $name = strtolower($name);
        } else {
            return null;
        }
        return ['platform' => 'youtube', 'name' => $name, 'key' => "youtube-$name", 'label' => 'YouTube'];
    }
    $name = strtolower($key);
    if ($p === 'kick') {
        if (!preg_match('/^[a-z0-9_-]{2,25}$/', $name)) return null;
        return ['platform' => 'kick', 'name' => $name, 'key' => "kick-$name", 'label' => 'Kick'];
    }
    if (!preg_match('/^[a-z0-9_]{3,25}$/', $name)) return null;
    return ['platform' => 'twitch', 'name' => $name, 'key' => $name, 'label' => 'Twitch'];
}

/** A link, "@name" or "kick:name" typed on the dashboard -> streamer key, or null. */
function platform_from_input(string $in): ?string
{
    $in = trim($in);
    if (preg_match('#^(?:https?://)?(?:www\.|m\.)?kick\.com/([A-Za-z0-9_-]+)#i', $in, $m)) $in = 'kick-' . $m[1];
    elseif (preg_match('#^(?:https?://)?(?:www\.|m\.)?youtube\.com/(?:@([A-Za-z0-9._-]+)|channel/(UC[A-Za-z0-9_-]{22}))#i', $in, $m)) $in = 'youtube-' . ($m[1] !== '' ? $m[1] : $m[2]);
    elseif (preg_match('#^(?:https?://)?(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]+)#i', $in, $m)) $in = $m[1];
    elseif ($in !== '' && $in[0] === '@') $in = 'youtube-' . substr($in, 1);
    $p = platform_parse($in);
    return $p ? $p['key'] : null;
}

/** The platform of a streamer key or folder ("twitch" when unknown). */
function platform_of(string $key): string
{
    return platform_parse($key)['platform'] ?? 'twitch';
}

if (!function_exists('http_request')) {
    /** Small stand-in for record.php's http_request (the dashboard has no record.php loaded). */
    function http_request(string $url, ?string $post, array $headers, int $timeout, int &$code = 0): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $body === false ? null : (string)$body;
    }
}
if (!function_exists('clean_text')) {
    function clean_text(string $s): string { return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? ''); }
}

/** Kick / YouTube address; SR_TEST_BASE points them at a local test server (automated tests only). */
function platform_url(string $url): string
{
    $t = (string)getenv('SR_TEST_BASE');
    return $t !== '' ? preg_replace('#^https://(www\.)?(kick\.com|youtube\.com)#', rtrim($t, '/') . '/$2', $url) : $url;
}

// ------------------------------------------------------------------ Kick

/** Kick's channel information (unofficial, also used by kick.com itself). null on error; ['exists' => false] when unknown. */
function kick_channel(string $slug, int &$code = 0): ?array
{
    $body = http_request(platform_url('https://kick.com/api/v2/channels/' . rawurlencode($slug)), null,
        ['Accept: application/json', 'User-Agent: ' . PLATFORM_UA], 10, $code);
    if ($code === 404) return ['exists' => false];
    if ($body === null || $code !== 200) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j + ['exists' => true] : null;
}

// ------------------------------------------------------------------ YouTube

/** The live video on a channel's /live page: ['exists', 'display', 'video_id', 'live', 'title', 'hls', 'started'] or null on error. */
function youtube_live(string $name, int &$code = 0): ?array
{
    $url = strpos($name, 'UC') === 0 && strlen($name) === 24
        ? "https://www.youtube.com/channel/$name/live" : 'https://www.youtube.com/@' . rawurlencode($name) . '/live';
    // The consent cookie skips the EU cookie page; English keeps the page's wording predictable.
    $html = http_request(platform_url($url), null, ['User-Agent: ' . PLATFORM_UA, 'Accept-Language: en-US,en;q=0.9',
        'Cookie: SOCS=CAI; CONSENT=YES+1'], 15, $code);
    if ($code === 404) return ['exists' => false];
    if ($html === null || $code !== 200) return null;
    $pr = null;
    if (preg_match('/ytInitialPlayerResponse\s*=\s*(\{.+?\})\s*;\s*(?:var\s|<\/script>)/s', $html, $m)) $pr = json_decode($m[1], true);
    $display = preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $t) ? html_entity_decode($t[1], ENT_QUOTES) : $name;
    $out = ['exists' => true, 'display' => $display, 'live' => false, 'blocked' => false];
    if (!$pr) {
        // "Sign in to confirm you're not a bot": YouTube refuses this server.
        $out['blocked'] = stripos($html, 'confirm you') !== false && stripos($html, 'not a bot') !== false;
        return $out;
    }
    $vd = $pr['videoDetails'] ?? [];
    $ps = $pr['playabilityStatus'] ?? [];
    $out['video_id'] = (string)($vd['videoId'] ?? '');
    $out['title'] = (string)($vd['title'] ?? '');
    $out['live'] = !empty($vd['isLive']) && ($ps['status'] ?? '') === 'OK';
    $out['hls'] = (string)($pr['streamingData']['hlsManifestUrl'] ?? '');
    $out['started'] = (string)($pr['microformat']['playerMicroformatRenderer']['liveBroadcastDetails']['startTimestamp'] ?? '');
    $out['blocked'] = ($ps['status'] ?? '') === 'LOGIN_REQUIRED';
    $out['reason'] = (string)($ps['reason'] ?? '');
    return $out;
}

// ------------------------------------------------------------------ the recorder's view (same shape as Twitch)

/**
 * Live check for Kick and YouTube, in the shape record.php uses for Twitch:
 * false on error, ['exists' => false], or ['exists' => true, 'display' => ..., 'stream' => null|[id, title, createdAt, game => [name]]].
 * The video address found on the way is kept in $GLOBALS['platformHls'] for platform_playlist().
 */
function platform_stream_info(array $P)
{
    $code = 0;
    if ($P['platform'] === 'kick') {
        $k = kick_channel($P['name'], $code);
        if ($k === null) { $GLOBALS['platformError'] = "Kick HTTP $code"; return false; }
        if (empty($k['exists'])) return ['exists' => false];
        $display = clean_text((string)($k['user']['username'] ?? $k['slug'] ?? $P['name']));
        $ls = $k['livestream'] ?? null;
        $GLOBALS['platformChat'] = (int)($k['chatroom']['id'] ?? 0);
        $stream = null;
        if (is_array($ls) && !empty($ls['is_live']) && !empty($k['playback_url'])) {
            $GLOBALS['platformHls'] = (string)$k['playback_url'];
            $stream = ['id' => preg_replace('/\D/', '', (string)($ls['id'] ?? '')) ?: '0',
                'title' => clean_text((string)($ls['session_title'] ?? '')),
                // Kick gives "2026-11-27 09:00:00" in UTC, without saying so.
                'createdAt' => (function ($t) { return preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $t) ? "$t UTC" : $t; })((string)($ls['created_at'] ?? $ls['start_time'] ?? date('c'))),
                'game' => ['name' => clean_text((string)($ls['categories'][0]['name'] ?? ''))],
                'viewers' => isset($ls['viewer_count']) ? (int)$ls['viewer_count'] : null];
        }
        return ['exists' => true, 'display' => preg_match('/^[\p{L}\p{N}_ -]{1,40}$/u', $display) ? $display : $P['name'], 'stream' => $stream];
    }
    if ($P['platform'] === 'youtube') {
        $y = youtube_live($P['name'], $code);
        if ($y === null) { $GLOBALS['platformError'] = "YouTube HTTP $code"; return false; }
        if (empty($y['exists'])) return ['exists' => false];
        if (!empty($y['blocked']) && empty($y['live'])) { $GLOBALS['platformError'] = 'YouTube asks this server to sign in (bot check)'; return false; }
        $display = clean_text((string)$y['display']);
        $stream = null;
        if (!empty($y['live']) && $y['hls'] !== '') {
            $GLOBALS['platformHls'] = $y['hls'];
            $stream = ['id' => preg_replace('/[^A-Za-z0-9_-]/', '', $y['video_id']) ?: '0', 'title' => clean_text($y['title']),
                'createdAt' => $y['started'] ?: date('c'), 'game' => ['name' => '']];
        }
        return ['exists' => true, 'display' => mb_substr($display, 0, 40) ?: $P['name'], 'stream' => $stream];
    }
    return false;
}

/** Media playlist of a live Kick / YouTube stream (best quality or QUALITY), or null. */
function platform_playlist(array $P, string &$chosen = '', bool $fresh = false): ?string
{
    $master = $GLOBALS['platformHls'] ?? '';
    if ($fresh || $master === '') {
        $i = platform_stream_info($P);
        if (!$i || empty($i['stream'])) return null;
        $master = $GLOBALS['platformHls'] ?? '';
    }
    if ($master === '') return null;
    $code = 0;
    $m = http_request($master, null, ['User-Agent: ' . PLATFORM_UA], 10, $code);
    if ($m === null || $code !== 200) return null;
    if (strpos($m, '#EXT-X-STREAM-INF') === false) { $chosen = 'source'; return $master; } // already a media playlist
    $url = pick_variant($m, $chosen);
    if ($url !== null && !preg_match('#^https?://#', $url)) $url = substr($master, 0, (int)strrpos(strtok($master, '?'), '/') + 1) . $url;
    return $url;
}

// ------------------------------------------------------------------ access test (dashboard button)

/**
 * Can this server reach Kick and YouTube Live? Returns one row per platform:
 * ['ok' => bool|null, 'detail' => text]. null = couldn't tell (for example no live test channel).
 */
function platform_access_test(): array
{
    $rows = [];
    $code = 0;
    $k = kick_channel('kick', $code);
    if ($k && !empty($k['exists']) && isset($k['chatroom'])) {
        $rows['kick'] = ['ok' => true, 'detail' => 'Kick answers this server (channel information and chat room).'];
    } elseif ($code === 403 || $code === 429 || $code === 503) {
        $rows['kick'] = ['ok' => false, 'detail' => "Kick refuses this server (HTTP $code, probably Cloudflare's bot protection). The live check can use Kick's official API instead: create a free Kick developer app and paste its two codes on the dashboard."];
    } else {
        $rows['kick'] = ['ok' => false, 'detail' => 'Kick did not answer as expected (' . ($code ? "HTTP $code" : 'no connection') . ').'];
    }
    // A channel that is live around the clock, to test the whole way to the video.
    $code = 0;
    $y = youtube_live('LofiGirl', $code);
    if ($y === null) {
        $rows['youtube'] = ['ok' => false, 'detail' => 'YouTube did not answer (' . ($code ? "HTTP $code" : 'no connection') . ').'];
    } elseif (!empty($y['blocked'])) {
        $rows['youtube'] = ['ok' => false, 'detail' => 'YouTube asks this server to sign in ("confirm you\'re not a bot"): it treats data-centre servers that way. Recording YouTube Live from here won\'t work; the PC summary tool can still use the finished video.'];
    } elseif (!empty($y['live']) && $y['hls'] !== '') {
        $c2 = 0;
        $m = http_request($y['hls'], null, ['User-Agent: ' . PLATFORM_UA], 15, $c2);
        $rows['youtube'] = ($m !== null && $c2 === 200 && strpos($m, '#EXTM3U') === 0)
            ? ['ok' => true, 'detail' => 'YouTube answers this server, including the live video itself.']
            : ['ok' => false, 'detail' => "YouTube shows the live page, but refuses the video itself (HTTP $c2)."];
    } else {
        $rows['youtube'] = ['ok' => null, 'detail' => 'YouTube answers, but the test channel is not live right now, so the video itself could not be tested. Try again later.'];
    }
    return $rows;
}

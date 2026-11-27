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

/** Twitch address; SR_TEST_BASE points it at a local test server (automated tests only). */
function twitch_url(string $url): string
{
    $t = (string)getenv('SR_TEST_BASE');
    return $t !== '' ? preg_replace('#^https://(gql\.twitch\.tv|usher\.ttvnw\.net)#', rtrim($t, '/') . '/$1', $url) : $url;
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
    $pr = json_object_after($html, 'ytInitialPlayerResponse');
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

// ------------------------------------------------------------------ chat: Kick (Pusher WebSocket)

/** Kick's public Pusher app (the one kick.com's own chat uses); config KICK_PUSHER_KEY overrides it if Kick changes it. */
function kick_pusher_key(): string
{
    return defined('KICK_PUSHER_KEY') ? (string)KICK_PUSHER_KEY : '32cbd69e4b950bf97679';
}

function kick_chat_connect(int $chatroom): ?array
{
    if ($chatroom <= 0) { logmsg('Kick chat: no chat room id from Kick'); return null; }
    $chat = ws_open('ws-us2.pusher.com', '/app/' . kick_pusher_key() . '?protocol=7&client=js&version=8.4.0&flash=false', 'https://kick.com');
    if (!$chat) return null;
    $chat['proto'] = 'pusher';
    $chat['room'] = $chatroom;
    return $chat;
}

/** One Pusher message from Kick: chat, subs, gifts, raids (hosts), bans. */
function kick_chat_frame(array &$chat, string $payload, array &$out): void
{
    $e = json_decode($payload, true);
    if (!is_array($e)) return;
    $ev = (string)($e['event'] ?? '');
    if ($ev === 'pusher:connection_established') {
        ws_send($chat, json_encode(['event' => 'pusher:subscribe', 'data' => ['auth' => '', 'channel' => 'chatrooms.' . $chat['room'] . '.v2']]));
        return;
    }
    if ($ev === 'pusher:ping') { ws_send($chat, json_encode(['event' => 'pusher:pong', 'data' => (object)[]])); return; }
    $d = is_string($e['data'] ?? null) ? json_decode($e['data'], true) : ($e['data'] ?? null);   // Pusher sends data as a JSON string
    if (!is_array($d)) return;
    $msg = kick_chat_message($ev, $d);
    if ($msg) $out[] = $msg;
}

/** A Kick event as a chat line: ['id', 'ts', 'text', 'emotes'] or null. Kept separate for the tests. */
function kick_chat_message(string $ev, array $d): ?array
{
    $name = function ($n) { $n = (string)$n; return preg_match('/^[\p{L}\p{N}_]{1,40}$/u', $n) ? $n : (preg_replace('/[^A-Za-z0-9_]/', '', $n) ?: 'someone'); };
    $ts = strtotime((string)($d['created_at'] ?? '')) ?: time();
    $emotes = [];
    $text = null;
    switch (substr($ev, strrpos($ev, '\\') !== false ? strrpos($ev, '\\') + 1 : 0)) {
        case 'ChatMessageEvent':
            // Emotes arrive as [emote:37226:KEKW]: shown as their name, pictures from Kick's site.
            $content = preg_replace_callback('/\[emote:(\d+):([A-Za-z0-9_]{1,60})\]/', function ($m) use (&$emotes) {
                $emotes[$m[2]] = 'https://files.kick.com/emotes/' . $m[1] . '/fullsize';
                return $m[2];
            }, (string)($d['content'] ?? ''));
            $text = $name($d['sender']['username'] ?? '') . ': ' . $content;
            break;
        case 'SubscriptionEvent':
            $text = '** ' . $name($d['username'] ?? '') . ' subscribed' . (!empty($d['months']) ? ' (' . (int)$d['months'] . ' months)' : '');
            break;
        case 'GiftedSubscriptionsEvent':
            $n = count((array)($d['gifted_usernames'] ?? []));
            $text = '** ' . $name($d['gifter_username'] ?? '') . ' is gifting ' . $n . ' sub' . ($n === 1 ? '' : 's');
            break;
        case 'StreamHostEvent':
            $text = '** ' . $name($d['host_username'] ?? '') . ' is raiding with ' . (int)($d['number_viewers'] ?? 0) . ' viewers';
            break;
        case 'UserBannedEvent':
            $who = $name($d['user']['username'] ?? '');
            $text = !empty($d['permanent']) ? "** $who was banned" : "** $who was timed out" . (!empty($d['duration']) ? ' for ' . ((int)$d['duration'] * 60) . 's' : '');
            break;
        case 'ChatroomClearEvent':
            $text = '** Chat was cleared by a moderator';
            break;
        default:
            return null;
    }
    $id = (string)($d['id'] ?? md5($ev . json_encode($d)));
    return ['id' => $id, 'ts' => $ts, 'text' => clean_text($text), 'emotes' => $emotes];
}

// ------------------------------------------------------------------ chat: YouTube (polled, like youtube.com's own chat)

function youtube_chat_connect(string $videoId): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) return null;
    $code = 0;
    $html = http_request(platform_url('https://www.youtube.com/live_chat?is_popout=1&v=' . $videoId), null,
        ['User-Agent: ' . PLATFORM_UA, 'Accept-Language: en-US,en;q=0.9', 'Cookie: SOCS=CAI; CONSENT=YES+1'], 15, $code);
    if ($html === null || $code !== 200) { logmsg("YouTube chat: page not available (HTTP $code)"); return null; }
    $key = preg_match('/"INNERTUBE_API_KEY"\s*:\s*"([A-Za-z0-9_-]+)"/', $html, $m) ? $m[1] : '';
    $ver = preg_match('/"INNERTUBE_CONTEXT_CLIENT_VERSION"\s*:\s*"([0-9.]+)"/', $html, $m) ? $m[1] : '2.20261101.00.00';
    $cont = preg_match('/"continuation"\s*:\s*"([A-Za-z0-9_%-]{20,})"/', $html, $m) ? $m[1] : '';
    if ($key === '' || $cont === '') { logmsg('YouTube chat: could not read the chat page (chat off, or YouTube refuses this server)'); return null; }
    return ['proto' => 'ytpoll', 'key' => $key, 'ver' => $ver, 'cont' => $cont, 'next' => microtime(true), 'fails' => 0];
}

/** Waits up to $timeout seconds; when YouTube's next poll is due, asks it for new chat. */
function youtube_chat_collect(?array &$chat, array &$out, float $timeout): void
{
    $wait = $chat['next'] - microtime(true);
    if ($wait > 0) { usleep((int)(min($wait, $timeout) * 1e6)); if ($wait > $timeout) return; }
    $code = 0;
    $body = http_request(platform_url('https://www.youtube.com/youtubei/v1/live_chat/get_live_chat?prettyPrint=false&key=' . rawurlencode($chat['key'])),
        json_encode(['context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => $chat['ver'], 'hl' => 'en']], 'continuation' => $chat['cont']]),
        ['Content-Type: application/json', 'User-Agent: ' . PLATFORM_UA, 'Origin: https://www.youtube.com'], 10, $code);
    $j = $body !== null && $code === 200 ? json_decode($body, true) : null;
    $lc = $j['continuationContents']['liveChatContinuation'] ?? null;
    if (!is_array($lc)) {
        if (++$chat['fails'] >= 5) { logmsg("YouTube chat stopped answering (HTTP $code) - will retry"); $chat = null; return; }
        $chat['next'] = microtime(true) + 5;
        return;
    }
    $chat['fails'] = 0;
    foreach ((array)($lc['actions'] ?? []) as $a) {
        $m = youtube_chat_message((array)($a['addChatItemAction']['item'] ?? []));
        if ($m) $out[] = $m;
    }
    $next = $lc['continuations'][0] ?? [];
    $c = $next['invalidationContinuationData'] ?? $next['timedContinuationData'] ?? $next['reloadContinuationData'] ?? null;
    if (!empty($c['continuation'])) $chat['cont'] = (string)$c['continuation'];
    $chat['next'] = microtime(true) + max(1.5, min(10, ((int)($c['timeoutMs'] ?? 3000)) / 1000));
}

/** One YouTube chat item: messages, Super Chats, new and gifted memberships. Kept separate for the tests. */
function youtube_chat_message(array $item): ?array
{
    $runs = function ($r, array &$emotes) {
        $t = '';
        foreach ((array)($r['runs'] ?? []) as $x) {
            if (isset($x['text'])) { $t .= $x['text']; continue; }
            $e = $x['emoji'] ?? null;
            if (!is_array($e)) continue;
            if (!empty($e['isCustomEmoji'])) {
                $n = trim((string)($e['shortcuts'][0] ?? ''), ':');
                $u = (string)($e['image']['thumbnails'][0]['url'] ?? '');
                if (strpos($u, '//') === 0) $u = "https:$u";
                if ($n !== '' && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $n)) { $t .= $n; if (function_exists('emote_url_ok') && emote_url_ok($u)) $emotes[$n] = $u; }
            } else {
                $t .= (string)($e['emojiId'] ?? '');
            }
        }
        return $t !== '' ? $t : (string)($r['simpleText'] ?? '');
    };
    $name = function ($r) { $n = ltrim((string)($r['simpleText'] ?? ''), '@'); $n = preg_replace('/[^\p{L}\p{N}_.-]+/u', '_', $n); return mb_substr($n !== '' ? $n : 'someone', 0, 40); };
    $emotes = [];
    $r = $item['liveChatTextMessageRenderer'] ?? null;
    if (is_array($r)) $text = $name($r['authorName'] ?? []) . ': ' . $runs($r['message'] ?? [], $emotes);
    elseif (is_array($r = $item['liveChatPaidMessageRenderer'] ?? null)) {
        $msg = $runs($r['message'] ?? [], $emotes);
        $text = '** ' . $name($r['authorName'] ?? []) . ' sent a Super Chat (' . ($r['purchaseAmountText']['simpleText'] ?? '?') . ')' . ($msg !== '' ? ": $msg" : '');
    } elseif (is_array($r = $item['liveChatMembershipItemRenderer'] ?? null)) {
        $text = '** ' . $name($r['authorName'] ?? []) . ' became a member' . (($h = $runs($r['headerSubtext'] ?? [], $emotes)) !== '' ? " ($h)" : '');
    } elseif (is_array($r = $item['liveChatSponsorshipsGiftPurchaseAnnouncementRenderer'] ?? null)) {
        $h = $r['header']['liveChatSponsorshipsHeaderRenderer'] ?? [];
        $n = preg_match('/(\d+)/', $runs($h['primaryText'] ?? [], $emotes), $m) ? (int)$m[1] : 1;
        $text = '** ' . $name($h['authorName'] ?? []) . " is gifting $n membership" . ($n === 1 ? '' : 's');
    } else {
        return null;
    }
    $ts = isset($r['timestampUsec']) ? ((int)$r['timestampUsec']) / 1e6 : time();
    return ['id' => (string)($r['id'] ?? md5(json_encode($item))), 'ts' => $ts, 'text' => clean_text($text), 'emotes' => $emotes];
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
            $GLOBALS['platformVideo'] = (string)$y['video_id'];
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

/** The JSON object that follows "$marker = " in a page (braces counted, strings skipped), or null. */
function json_object_after(string $html, string $marker): ?array
{
    if (!preg_match('/' . preg_quote($marker, '/') . '"?\]?\s*=\s*\{/', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    $start = $m[0][1] + strlen($m[0][0]) - 1;
    $depth = 0;
    $inStr = false;
    for ($i = $start, $n = strlen($html); $i < $n; $i++) {
        $c = $html[$i];
        if ($inStr) { if ($c === '\\') $i++; elseif ($c === '"') $inStr = false; continue; }
        if ($c === '"') $inStr = true;
        elseif ($c === '{') $depth++;
        elseif ($c === '}' && --$depth === 0) {
            $j = json_decode(substr($html, $start, $i - $start + 1), true);
            return is_array($j) ? $j : null;
        }
    }
    return null;
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
    // Channels that are live around the clock, to test the whole way to the video (the first one that is live counts).
    $y = null;
    $seen = [];
    foreach (['LofiGirl', 'SkyNews', 'aljazeeraenglish', 'NASA', 'France24_en'] as $ch) {
        $code = 0;
        $y = youtube_live($ch, $code);
        if ($y === null) { $seen[] = "$ch: " . ($code ? "HTTP $code" : 'no answer'); continue; }
        if (!empty($y['blocked']) || (!empty($y['live']) && $y['hls'] !== '')) break;
        $seen[] = "$ch: " . (empty($y['exists']) ? 'not found' : (($y['video_id'] ?? '') === '' ? 'no live video on its page' : 'not live' . (!empty($y['reason']) ? ' (' . $y['reason'] . ')' : '')));
        $y = null;
    }
    if ($y === null) {
        $rows['youtube'] = ['ok' => null, 'detail' => 'YouTube answers, but none of the test channels showed a live video (' . implode('; ', $seen) . '), so the video itself could not be tested. Try again later.'];
    } elseif (!empty($y['blocked'])) {
        $rows['youtube'] = ['ok' => false, 'detail' => 'YouTube asks this server to sign in ("confirm you\'re not a bot"): it treats data-centre servers that way. Recording YouTube Live from here won\'t work; the PC summary tool can still use the finished video.'];
    } else {
        $c2 = 0;
        $m = http_request($y['hls'], null, ['User-Agent: ' . PLATFORM_UA], 15, $c2);
        $rows['youtube'] = ($m !== null && $c2 === 200 && strpos($m, '#EXTM3U') === 0)
            ? ['ok' => true, 'detail' => 'YouTube answers this server, including the live video itself (tested on ' . $y['display'] . ').']
            : ['ok' => false, 'detail' => "YouTube shows the live page, but refuses the video itself (HTTP $c2)."];
    }
    // Viewer counts: Twitch's own player API, asked for the biggest live stream right now.
    $code = 0;
    $body = http_request('https://gql.twitch.tv/gql', json_encode(['query' => '{ streams(first: 1) { edges { node { viewersCount broadcaster { login } } } } }']),
        ['Client-ID: kimne78kx3ncx6brgo4mv6wki5h1ko', 'Content-Type: application/json'], 10, $code);
    $n = json_decode((string)$body, true)['data']['streams']['edges'][0]['node'] ?? null;
    $rows['viewers'] = is_array($n) && isset($n['viewersCount'])
        ? ['ok' => true, 'detail' => 'Twitch gives viewer counts (biggest stream now: ' . number_format((int)$n['viewersCount']) . ' viewers).']
        : ['ok' => false, 'detail' => 'Twitch did not give a viewer count (' . ($code ? "HTTP $code" : 'no connection') . ').'];
    // Emote pictures: the three emote services' lists of global emotes.
    if (function_exists('emote_fetch')) {
        $got = [];
        foreach (['BetterTTV' => 'https://api.betterttv.net/3/cached/emotes/global', 'FrankerFaceZ' => 'https://api.frankerfacez.com/v1/set/global',
            '7TV' => 'https://7tv.io/v3/emote-sets/global'] as $name => $url) {
            $got[$name] = emote_fetch($url) !== null;
        }
        $ok = array_keys(array_filter($got));
        $bad = array_keys(array_diff_key($got, array_flip($ok)));
        $rows['emotes'] = ['ok' => $bad ? ($ok ? null : false) : true,
            'detail' => trim(($ok ? 'Emote lists from ' . implode(', ', $ok) . ' work.' : '') . ($bad ? ' No answer from ' . implode(', ', $bad) . '.' : ''))];
    }
    return $rows;
}

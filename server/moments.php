<?php
/**
 * Stream Recorder™ - "moments": rules that scan a recording's chat and stream info for specific
 * moments and tag the YouTube title/description when found. Rules are edited on the dashboard and
 * stored in moments.json next to record.php. Used by record.php (after a stream and before a YouTube
 * upload) and by the dashboard (the Test button). Pure functions, no side effects.
 *
 * Rule: ['id', 'enabled', 'channel' ('' = all streamers), 'type', 'value', 'count', 'window', 'label', 'title' (bool)]
 *   type 'text'     - a chat message (also raids/subs/system messages) contains value
 *   type 'user'     - a message from user value
 *   type 'burst'    - at least count messages containing value (any message if empty) within window seconds
 *   type 'category' - the stream's category or title contains value (at the start or after a change)
 */

const MOMENT_TYPES = ['text', 'user', 'burst', 'category', 'speech'];

// Without PHP's mbstring extension: simple stand-ins (case-insensitive for plain letters only).
if (!function_exists('mb_stripos')) { function mb_stripos($h, $n) { return stripos($h, $n); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }

function moments_rules(string $file): array
{
    $r = json_decode((string)@file_get_contents($file), true);
    return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
}

/** Chat time (local "Y-m-d H:i:s[.mmm]") -> seconds into the video, like the player does. */
function moments_mapper(array $meta, ?string $fallbackStart = null): callable
{
    $tz = new DateTimeZone(in_array($meta['timezone'] ?? '', timezone_identifiers_list(), true) ? $meta['timezone'] : date_default_timezone_get());
    $ms = function (string $s) use ($tz): ?float {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\.(\d{1,3}))?/', $s, $m)) return null;
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $m[1], $tz);
        return $d ? $d->getTimestamp() * 1000 + (int)str_pad($m[2] ?? '0', 3, '0') : null;
    };
    $tl = [];
    foreach ($meta['timeline'] ?? [] as $e) {
        if (is_array($e) && count($e) >= 2 && ($t = $ms((string)$e[1])) !== null) $tl[] = [(float)$e[0], $t];
    }
    $start = isset($meta['video_start']) ? $ms((string)$meta['video_start']) : null;
    if ($start === null && $fallbackStart !== null) $start = $ms($fallbackStart); // like the player: first chat message = 0:00
    return function (string $time) use ($tl, $start, $ms): ?float {
        $t = $ms($time);
        if ($t === null) return null;
        if (!$tl) return $start !== null ? max(0, ($t - $start) / 1000) : null;
        $i = 0;
        while ($i + 1 < count($tl) && $tl[$i + 1][1] <= $t) $i++;
        $v = $tl[$i][0] + ($t - $tl[$i][1]) / 1000;
        if ($i + 1 < count($tl)) $v = min($v, $tl[$i + 1][0]); // during an ad break: at the cut
        return max(0, $v);
    };
}

/**
 * Scan one recording folder. Returns ['moments' => [[t, label, detail], ...] sorted by time,
 * 'labels' => labels for the title, 'checked' => time()].
 */
function moments_scan(string $dir, array $rules, string $channel): array
{
    $meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
    $rules = array_values(array_filter($rules, function ($r) use ($channel) {
        return !empty($r['enabled']) && in_array($r['type'] ?? '', MOMENT_TYPES, true)
            && trim((string)($r['label'] ?? '')) !== '' && (($r['channel'] ?? '') === '' || $r['channel'] === $channel);
    }));
    $found = [];
    $add = function (array $r, float $t, string $detail) use (&$found) {
        $id = (string)$r['id'];
        foreach ($found[$id] ?? [] as $f) if (abs($f[0] - $t) < 60) return; // one per minute per rule
        if (count($found[$id] ?? []) >= 50) return;
        $found[$id][] = [$t, (string)$r['label'], $detail];
    };
    $cut = function (string $s, int $n) { return function_exists('mb_strimwidth') ? mb_strimwidth($s, 0, $n, '…') : substr($s, 0, $n); };

    // Stream info: category/title at the start and after each change.
    $points = [];
    foreach ($meta['changes'] ?? [] as $c) $points[] = [(float)($c['video'] ?? 0), (string)($c['title'] ?? ''), (string)($c['game'] ?? '')];
    if (!$points) $points[] = [0.0, (string)($meta['title'] ?? ''), (string)($meta['game'] ?? '')];
    foreach ($rules as $r) {
        if ($r['type'] !== 'category') continue;
        $v = mb_strtolower(trim((string)$r['value']));
        foreach ($points as [$t, $title, $game]) {
            if ($v !== '' && (mb_stripos($game, $v) !== false || mb_stripos($title, $v) !== false)) $add($r, $t, $game !== '' ? $game : $title);
        }
    }

    // What the streamer said: transcript.json from the PC scan (original text and its translation).
    $speechRules = array_values(array_filter($rules, function ($r) { return $r['type'] === 'speech'; }));
    if ($speechRules && is_file("$dir/transcript.json")) {
        $tr = json_decode((string)@file_get_contents("$dir/transcript.json"), true) ?: [];
        foreach ($tr['segments'] ?? [] as $seg) {
            if (!is_array($seg) || !isset($seg[0], $seg[2])) continue;
            $said = (string)$seg[2];
            $trans = (string)($seg[3] ?? '');
            foreach ($speechRules as $r) {
                $v = trim((string)($r['value'] ?? ''));
                if ($v === '') continue;
                if (mb_stripos($said, $v) !== false || ($trans !== '' && mb_stripos($trans, $v) !== false)) {
                    $add($r, (float)$seg[0], $cut('"' . trim($said) . '"', 80));
                }
            }
        }
    }

    // Chat, line by line (chat files can be large).
    $chatRules = array_values(array_filter($rules, function ($r) { return !in_array($r['type'], ['category', 'speech'], true); }));
    $h = $chatRules ? @fopen("$dir/chat.txt", 'rb') : false;
    if ($h) {
        // Sync info can also (or only) be in chat.txt's first line, like the player reads it.
        $first = fgets($h);
        if ($first !== false && strpos($first, '#META ') === 0) {
            $hm = json_decode(substr(rtrim($first, "\r\n"), 6), true);
            if (is_array($hm)) $meta = $meta + $hm; // meta.json wins where both have a value
        } else {
            rewind($h);
        }
        $map = null;
        $windows = []; // burst rule id -> list of times in the window
        while (($line = fgets($h)) !== false) {
            if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d+)?)\] (.*)$/u', rtrim($line, "\r\n"), $m)) continue;
            $body = $m[2];
            $name = '';
            $text = $body;
            if (strpos($body, '** ') === 0) $text = substr($body, 3);
            elseif (strpos($body, '* ') === 0) { $p = strpos($body, ' ', 2); $name = substr($body, 2, $p - 2); $text = substr($body, $p + 1); }
            elseif (($p = strpos($body, ': ')) !== false && $p < 40) { $name = substr($body, 0, $p); $text = substr($body, $p + 2); }
            $t = null;
            foreach ($chatRules as $r) {
                $v = trim((string)($r['value'] ?? ''));
                $hit = false;
                if ($r['type'] === 'text') $hit = $v !== '' && mb_stripos($body, $v) !== false;
                elseif ($r['type'] === 'user') $hit = $v !== '' && strcasecmp($name, $v) === 0;
                elseif ($r['type'] === 'burst') $hit = $v === '' || mb_stripos($text, $v) !== false;
                if (!$hit) continue;
                if (!$map) $map = moments_mapper($meta, $m[1]);
                $t = $t ?? $map($m[1]);
                if ($t === null) continue;
                if ($r['type'] === 'burst') {
                    $need = max(2, (int)($r['count'] ?? 10));
                    $win = max(5, (int)($r['window'] ?? 30));
                    $id = (string)$r['id'];
                    $windows[$id][] = $t;
                    while ($windows[$id] && $t - $windows[$id][0] > $win) array_shift($windows[$id]);
                    if (count($windows[$id]) >= $need) {
                        $w0 = $windows[$id][0];
                        $add($r, $w0, count($windows[$id]) . ' messages in ' . max(1, (int)round($t - $w0)) . ' s' . ($v !== '' ? ' with "' . $v . '"' : ''));
                        $windows[$id] = [];
                    }
                } else {
                    $add($r, $t, $cut(($name !== '' ? "$name: " : '') . $text, 80));
                }
            }
        }
        fclose($h);
    }

    $moments = [];
    $labels = [];
    foreach ($rules as $r) {
        foreach ($found[(string)$r['id']] ?? [] as $f) $moments[] = $f;
        if (!empty($found[(string)$r['id']]) && !empty($r['title']) && !in_array($r['label'], $labels, true)) $labels[] = (string)$r['label'];
    }
    usort($moments, function ($a, $b) { return $a[0] <=> $b[0]; });
    return ['moments' => $moments, 'labels' => $labels, 'checked' => time()];
}

function moments_clock(float $t): string
{
    $t = (int)round($t);
    return sprintf('%d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60);
}

/** YouTube title with the labels: "Base | Label 1 | Label 2", max 100 characters (the base is shortened first). */
function moments_title(string $base, array $labels): string
{
    $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); };
    $sub = function ($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); };
    $suffix = $labels ? ' | ' . implode(' | ', $labels) : '';
    if ($len($suffix) > 60) $suffix = $sub($suffix, 59) . '…';
    $room = 100 - $len($suffix);
    if ($len($base) > $room) $base = $sub($base, $room - 1) . '…';
    return $base . $suffix;
}

/** The "Moments" block for the YouTube description (timestamps become clickable on YouTube). */
function moments_description(array $moments): string
{
    if (!$moments) return '';
    $out = "Moments:\n";
    foreach (array_slice($moments, 0, 60) as [$t, $label, $detail]) {
        $out .= moments_clock((float)$t) . ' ' . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
    if (count($moments) > 60) $out .= '… and ' . (count($moments) - 60) . " more\n";
    return $out . "\n";
}

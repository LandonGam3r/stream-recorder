<?php
/**
 * Text archive and storage rules (required by record.php and the dashboard).
 *
 * "Delete footage" removes only the video and sound of a recording. Everything that is text stays:
 * chat.txt, meta.json, the transcript and subtitles, the AI summary (and its small pictures), moments.
 * The recording stays on the dashboard, marked "text only", and stays searchable.
 *
 * Storage rules per streamer (channels.json "storage"):
 *   footage_days  delete the footage of finished recordings older than this many days (0 = never)
 *   keep_last     keep the footage of only the newest N finished recordings (0 = all)
 * The text is never deleted by a rule; deleting a whole recording is always done by hand.
 */

/** Video and sound files of a recording (relative names), with their sizes. */
function footage_files(string $d): array
{
    $out = [];
    foreach (['video.mp4', 'video.ts', 'video.part.mp4', '.concat.txt', '.scan_concat.txt'] as $f) {
        if (is_file("$d/$f")) $out[$f] = (int)filesize("$d/$f");
    }
    foreach (['parts', 'audio'] as $sub) {
        foreach (glob("$d/$sub/*") ?: [] as $f) {
            if (is_file($f)) $out["$sub/" . basename($f)] = (int)filesize($f);
        }
    }
    return $out;
}

/** Deletes the footage of a recording and marks meta.json. Returns bytes freed, or null when something stayed. */
function delete_footage(string $d, string $why): ?int
{
    $files = footage_files($d);
    $bytes = array_sum($files);
    foreach (array_keys($files) as $f) @unlink("$d/$f");
    foreach (['parts', 'audio'] as $sub) {
        if (is_dir("$d/$sub")) {
            foreach (glob("$d/$sub/.*") ?: [] as $f) if (is_file($f)) @unlink($f); // .exit, .progress
            @rmdir("$d/$sub");
        }
    }
    foreach (['.convert_progress.txt', '.convert_exit', '.convert_pid'] as $f) @unlink("$d/$f");
    clearstatcache();
    if (footage_files($d)) return null;
    $mf = "$d/meta.json";
    $meta = is_file($mf) ? (json_decode((string)file_get_contents($mf), true) ?: []) : [];
    $meta['footage_deleted'] = date('Y-m-d H:i:s');
    $meta['footage_deleted_why'] = $why;
    $meta['footage_bytes'] = ($meta['footage_bytes'] ?? 0) + $bytes;
    file_put_contents($mf . '.tmp', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($mf . '.tmp', $mf);
    return $bytes;
}

/** "3.2 GB" / "450 MB" for the logs. */
function size_text(int $b): string
{
    return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : sprintf('%d MB', (int)round($b / 1048576));
}

/** A recording's footage is gone (the text stays). */
function footage_deleted(string $d): bool
{
    $m = json_decode((string)@file_get_contents("$d/meta.json"), true);
    return is_array($m) && !empty($m['footage_deleted']);
}

/** Checks and normalises a storage setting from the dashboard; null when invalid. */
function storage_setting($in): ?array
{
    if (!is_array($in)) return null;
    $days = $in['footage_days'] ?? 0;
    $last = $in['keep_last'] ?? 0;
    if (!is_numeric($days) || !is_numeric($last)) return null;
    $days = (int)$days;
    $last = (int)$last;
    if ($days < 0 || $days > 3650 || $last < 0 || $last > 1000) return null;
    return ['footage_days' => $days, 'keep_last' => $last];
}

/**
 * Which finished recordings of a streamer lose their footage under its rule: [folder path => reason].
 * $busy(path) says whether a recording is in use (recording, converting, uploading, scanning, summary).
 * Pure decision, so the tests can check it without files being deleted.
 */
function storage_plan(string $chanDir, array $rule, callable $busy, ?int $now = null): array
{
    $now = $now ?? time();
    $days = (int)($rule['footage_days'] ?? 0);
    $last = (int)($rule['keep_last'] ?? 0);
    if ($days <= 0 && $last <= 0) return [];
    $done = [];
    foreach (glob("$chanDir/*", GLOB_ONLYDIR) ?: [] as $d) {
        $m = json_decode((string)@file_get_contents("$d/meta.json"), true);
        if (!is_array($m) || empty($m['stream_ended'])) continue; // not finished (or not a recording)
        $done[$d] = (int)strtotime((string)$m['stream_ended']);
    }
    arsort($done); // newest first
    $plan = [];
    $i = 0;
    foreach ($done as $d => $ended) {
        $i++;
        if (footage_deleted($d) || !footage_files($d) || $busy($d)) continue;
        if (!empty(json_decode((string)@file_get_contents("$d/meta.json"), true)['keep_footage'])) continue; // pinned on the dashboard
        if ($last > 0 && $i > $last) $plan[$d] = "only the newest $last are kept";
        elseif ($days > 0 && $ended > 0 && $now - $ended > $days * 86400) $plan[$d] = "older than $days days";
    }
    return $plan;
}

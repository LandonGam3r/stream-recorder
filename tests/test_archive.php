<?php
// "Delete video only" keeps every text file; storage rules pick the right recordings.

$chan = temp_dir() . '/somestreamer';
$mk = function (string $folder, string $ended, bool $video = true) use ($chan) {
    $d = "$chan/$folder";
    put("$d/meta.json", json_encode(['title' => $folder, 'stream_ended' => $ended]));
    put("$d/chat.txt", "[2026-11-01 10:00:00] someone: hi\n");
    put("$d/transcript.json", '{"parts":{}}');
    put("$d/ai_summary.md", '# Summary');
    put("$d/ai_frames/f001.jpg", 'jpg');
    if ($video) { put("$d/video.mp4", str_repeat('v', 1000)); put("$d/parts/001.ts", str_repeat('t', 500)); put("$d/audio/000.webm", 'a'); put("$d/audio/.exit", '0'); }
    return $d;
};
$now = strtotime('2026-11-27 12:00:00');
$old  = $mk('2026-10-01_100000_1', '2026-10-01 14:00:00');
$mid  = $mk('2026-11-10_100000_2', '2026-11-10 14:00:00');
$new  = $mk('2026-11-26_100000_3', '2026-11-26 14:00:00');
$live = "$chan/2026-11-27_100000_4"; put("$live/parts/001.ts", 'still recording'); put("$live/meta.json", '{}'); // not finished

same(array_sum(footage_files($old)), 1501, 'footage files and sizes');
same(storage_plan($chan, ['footage_days' => 0, 'keep_last' => 0], fn() => false, $now), [], 'no rule, nothing deleted');
same(array_keys(storage_plan($chan, ['footage_days' => 30], fn() => false, $now)), [$old], '30 days: only the oldest');
same(array_keys(storage_plan($chan, ['keep_last' => 1], fn() => false, $now)), [$mid, $old], 'keep newest 1: the two older ones');
same(array_keys(storage_plan($chan, ['keep_last' => 1], fn($d) => $d === $mid, $now)), [$old], 'a busy recording is left alone');
check(!isset(storage_plan($chan, ['keep_last' => 1], fn() => false, $now)[$live]), 'a recording still in progress is never touched');

$m = json_decode(file_get_contents("$mid/meta.json"), true); $m['keep_footage'] = true; file_put_contents("$mid/meta.json", json_encode($m));
same(array_keys(storage_plan($chan, ['keep_last' => 1], fn() => false, $now)), [$old], '"Keep video" is respected');

same(delete_footage($old, 'test'), 1501, 'bytes freed');
foreach (['chat.txt', 'transcript.json', 'ai_summary.md', 'ai_frames/f001.jpg', 'meta.json'] as $f) check(is_file("$old/$f"), "$f is kept");
foreach (['video.mp4', 'parts', 'audio'] as $f) check(!file_exists("$old/$f"), "$f is gone");
check(footage_deleted($old), 'marked as text only');
same(json_decode(file_get_contents("$old/meta.json"), true)['title'], '2026-10-01_100000_1', 'meta.json keeps its contents');
same(array_keys(storage_plan($chan, ['footage_days' => 30], fn() => false, $now)), [], 'text-only recordings are not planned again');

same(storage_setting(['footage_days' => '30', 'keep_last' => 0]), ['footage_days' => 30, 'keep_last' => 0], 'setting from the dashboard');
same(storage_setting(['footage_days' => -1]), null, 'negative refused');
same(storage_setting(['keep_last' => 'x']), null, 'text refused');
same(storage_setting('30'), null, 'not a list refused');

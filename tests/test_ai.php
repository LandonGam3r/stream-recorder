<?php
// The summary's extras (chapters, clips, thumbnail), YouTube description, costs, clips and questions.
if (!function_exists('ai_take_extras')) { check(false, 'ai.php is loaded'); return; }

same(ai_seconds('1:02:03'), 3723.0, 'h:mm:ss');
same(ai_seconds('[2:05]'), 125.0, 'm:ss in brackets');
same(ai_seconds('x'), null, 'not a time');

$d = temp_dir() . '/rec';
mkdir($d);
$answer = "# Headline\n**In short:** fun.\n\n<extras>\n" . json_encode([
    'chapters' => [['0:00:30', 'Start'], ['0:10:00', 'Raid <b>time</b> https://evil.example'], ['0:10:30', 'too close'], ['0:30:00', 'Boss fight'], ['5:00:00', 'past the end']],
    'clips' => [['0:09:50', '0:10:30', 'The raid'], ['0:20:00', '0:25:00', 'Too long, gets cut'], ['0:40:00', '0:39:00', 'backwards']],
    'thumbnail' => ['picture' => '0:10:00', 'text' => 'RAID TIME!'],
]) . "\n</extras>\n";
$text = ai_take_extras($d, $answer, 3600);
same($text, "# Headline\n**In short:** fun.", 'the block is taken out of the summary');
$x = json_decode(file_get_contents("$d/ai_extras.json"), true);
same(array_column($x['chapters'], 0), [0, 600, 1800], 'chapters: first at 0, too close and past the end dropped');
same($x['chapters'][1][1], 'Raid time', 'chapter titles cleaned (no tags, no links)');
same($x['clips'], [[590, 630, 'The raid'], [1200, 1290, 'Too long, gets cut']], 'clips checked and capped at 90 s');
same($x['thumbnail'], ['t' => 600, 'text' => 'RAID TIME!'], 'thumbnail');
same(ai_take_extras($d, 'no block here', 100), 'no block here', 'a summary without the block stays as it is');

// Chapters in the YouTube description (a channel's chapters setting is on by default).
$desc = youtube_description(['channel' => 'Someone', 'stream_started' => '2026-11-27 20:00:00', 'title' => 'T', 'recorded_seconds' => 3600,
    'changes' => [['video' => 0, 'game' => 'A'], ['video' => 900, 'game' => 'B']]], [], $d);
check(strpos($desc, "Chapters:\n0:00:00 Start\n0:10:00 Raid time\n0:30:00 Boss fight") !== false, 'chapters in the description');
check(strpos($desc, 'Categories: A, B') !== false && strpos($desc, '0:15:00 B') === false, 'categories without times next to the chapters');
$desc2 = youtube_description(['channel' => 'Someone', 'stream_started' => '2026-11-27 20:00:00', 'title' => 'T', 'recorded_seconds' => 3600,
    'changes' => [['video' => 0, 'game' => 'A'], ['video' => 900, 'game' => 'B']]], []);
check(strpos($desc2, 'Chapters:') === false && strpos($desc2, '0:15:00 B') !== false, 'no summary: categories with times as before');

// Prices: standard is twice the batch price, cached input a tenth.
$m = SUMMARY_DEFAULT_MODEL;
$p = SUMMARY_MODELS[$m]['price'][0];
same(ai_cost($m, ['input_tokens' => 100000]), round(2 * 100000 * $p[0] / 1e6, 5), 'input price');
same(ai_cost($m, ['cache_read_input_tokens' => 50000, 'output_tokens' => 1000]), round(2 * (50000 * $p[0] * 0.1 + 1000 * $p[1]) / 1e6, 5), 'cached input and output');

// Clips: only from the summary's list, one of each kind.
put("$d/video.mp4", 'v');
same(clip_request($d, 0, 'short', ''), null, 'a Short is queued');
same(clip_request($d, 0, 'short', ''), 'That one is already made or being made.', 'not twice');
same(clip_request($d, 0, 'clip', 'main'), null, 'a clip of the same moment is fine');
same(clip_request($d, 9, 'clip', ''), 'That clip is not in the summary anymore.', 'unknown clip');
$cl = json_decode(file_get_contents("$d/clips.json"), true);
same([count($cl), $cl[0]['start'], $cl[0]['end'], $cl[1]['upload']], [2, 590, 630, 'main'], 'clips.json');
check(preg_match('/^[a-f0-9]{8}$/', $cl[0]['id']) === 1, 'clip id');

// Questions: the material is fitted and marked as material.
put("$d/meta.json", json_encode(['title' => 'Raid night', 'video_start' => '2026-11-27 20:00:00.000', 'recorded_seconds' => 3600]));
put("$d/chat.txt", "[2026-11-27 20:00:10] alice: hello\n[2026-11-27 20:10:00] ** Someone is raiding\n");
put("$d/ai_summary.md", "# Raid night\n- [0:10:00] the raid\n");
$mat = ask_material($d, 120000);
check(strpos($mat, '<stream_material>') === 0 && substr($mat, -18) === '</stream_material>', 'material in tags');
check(strpos($mat, "## Summary\n# Raid night") !== false && strpos($mat, 'alice: hello') !== false && strpos($mat, '(notice) Someone is raiding') !== false, 'summary and chat in it');

// The YouTube upload waits for an AI summary that is being made, for at most 2 hours.
put("$d/ai_summary.json", json_encode(['state' => 'submitted']));
check(ai_holds_upload($d, time() - 60), 'waits while the summary is being made');
check(!ai_holds_upload($d, time() - 7300), 'gives up after 2 hours');
put("$d/ai_summary.json", json_encode(['state' => 'done']));
check(!ai_holds_upload($d, time()), 'no wait once the summary is done');

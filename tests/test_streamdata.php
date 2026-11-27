<?php
// Emotes, viewer counts, bookmarks, chat time mapping and searching all recordings.

same(twitch_message_emotes('25:0-4,12-16/1902:6-10', 'Kappa Keepo Kappa'), ['Kappa' => '25', 'Keepo' => '1902'], 'emote positions');
same(twitch_message_emotes('emotesv2_abc123:3-20', 'hé PogU!'), [], 'positions past the end of the text are ignored');
same(twitch_message_emotes('emotesv2_abc123:3-6', 'hé PogU'), ['PogU' => 'emotesv2_abc123'], 'positions count characters, not bytes');
$m = format_chat_line("@display-name=A;emotes=25:0-4;id=e :a!a@x PRIVMSG #c :\x01ACTION Kappa\x01");
same($m['emotes'] ?? null, ['Kappa' => '25'], 'emotes inside a /me message');
same(format_chat_line('@display-name=A;id=f :a!a@x PRIVMSG #c :no emotes')['emotes'] ?? null, [], 'no emotes');

check(emote_url_ok('https://cdn.7tv.app/emote/01ABC/1x.webp'), '7TV picture allowed');
check(!emote_url_ok('http://cdn.7tv.app/emote/01ABC/1x.webp'), 'plain http refused');
check(!emote_url_ok('https://evil.example/x.png'), 'other sites refused');
check(!emote_url_ok('https://cdn.7tv.app@evil.example/x'), 'user@host trick refused');

$d = temp_dir() . '/rec';
mkdir($d);
emotes_merge($d, ['Kappa' => '25']);
emotes_merge($d, ['PogU' => 'emotesv2_x', 'bad' => '../x']);
same(emotes_load($d)['twitch'], ['Kappa' => '25', 'PogU' => 'emotesv2_x'], 'emotes merged, bad id dropped');

viewers_append($d, 61.4, 1200);
viewers_append($d, 121, -5);
same(viewers_read($d), [[61, 1200], [121, 0]], 'viewer counts');

same(bookmarks_update($d, function (array &$l) { $l[] = ['id' => 'b', 't' => 90, 'note' => 'raid']; $l[] = ['id' => 'a', 't' => 30, 'note' => 'funny']; return null; }), null, 'bookmarks saved');
same(array_column(bookmarks_load($d), 'id'), ['a', 'b'], 'bookmarks sorted by time');
same(bookmarks_update($d, function (array &$l) { $l = []; return 'nope'; }), 'nope', 'an error leaves the file alone');
same(count(bookmarks_load($d)), 2, 'still two bookmarks');

// Video part 2 starts at 100 s, 10:10:00 (an 8-minute ad break isn't in the video).
$map = chat_mapper(['video_start' => '2026-11-27 10:00:00.000', 'timeline' => [[0, '2026-11-27 10:00:00.000'], [100, '2026-11-27 10:10:00.000']]]);
same($map(local_ms('2026-11-27 10:00:30')), 30.0, 'first part');
same($map(local_ms('2026-11-27 10:05:00')), 100.0, 'during the cut: at the cut');
same($map(local_ms('2026-11-27 10:10:05.500')), 105.5, 'second part');

// Searching: two streamers, chat + transcript + summary + bookmark.
$root = temp_dir();
$a = "$root/somestreamer/2026-11-20_100000_1";
put("$a/meta.json", json_encode(['title' => 'Raid night', 'video_start' => '2026-11-20 10:00:00.000', 'stream_started' => '2026-11-20 10:00:00']));
put("$a/chat.txt", "#META {}\n[2026-11-20 10:00:10] alice: hello\n[2026-11-20 10:00:20] bob: what a RAID\n[2026-11-20 10:00:30] carol: wow\n[2026-11-20 10:00:40] dave: gg\n[2026-11-20 10:00:50] ** Someone is raiding with 50 viewers\n");
put("$a/transcript.json", json_encode(['parts' => ['0' => [[5, 8, 'welcome everyone'], [60, 63, 'here comes the raid'], [64, 66, 'thank you']]]]));
put("$a/ai_summary.md", "# Summary\n- [0:01:00] A big raid arrives\n");
put("$a/bookmarks.json", json_encode([['id' => 'x', 't' => 61, 'note' => 'raid moment']]));
$b = "$root/kick-other/2026-11-25_100000_2";
put("$b/meta.json", json_encode(['title' => 'Quiet stream', 'video_start' => '2026-11-25 10:00:00.000']));
put("$b/chat.txt", "[2026-11-25 10:01:00] bob: no raid today\n");

$r = search_recordings($root, 'raid');
$kinds = array_count_values(array_column($r['hits'], 'kind'));
same($r['total'], 2, 'two recordings');
same($kinds, ['chat' => 3, 'title' => 1, 'summary' => 1, 'bookmark' => 1, 'speech' => 1], 'hits of every kind');
same($r['hits'][0]['channel'], 'kick-other', 'newest recording first');
$chat = array_values(array_filter($r['hits'], function ($h) { return $h['kind'] === 'chat' && $h['channel'] === 'somestreamer'; }));
same($chat[0]['t'], 20.0, 'chat hit has its video time');
same($chat[0]['name'], 'bob', 'chat hit has the name');
same($chat[0]['before'], ['alice: hello'], 'a line before');
same($chat[0]['after'], ['carol: wow', 'dave: gg'], 'two lines after');
$sp = array_values(array_filter($r['hits'], function ($h) { return $h['kind'] === 'speech'; }));
same([$sp[0]['t'], $sp[0]['before'], $sp[0]['after']], [60.0, ['welcome everyone'], ['thank you']], 'speech hit with context');
same(array_values(array_filter($r['hits'], function ($h) { return $h['kind'] === 'summary'; }))[0]['t'], 60, 'summary time from [0:01:00]');

same(count(search_recordings($root, 'raid', ['channel' => 'kick-other'])['hits']), 1, 'one streamer only');
same(count(search_recordings($root, 'raid', ['kinds' => ['speech']])['hits']), 1, 'only spoken words');
same(array_column(search_recordings($root, 'BOB', ['field' => 'name'])['hits'], 'text'), ['no raid today', 'what a RAID'], 'by username, any case');
$lim = search_recordings($root, 'raid', ['limit' => 2]);
same([count($lim['hits']), $lim['more']], [2, true], 'limit and "more"');
same(search_recordings($root, '')['hits'], [], 'empty search finds nothing');

<?php
// Kick and YouTube chat lines, emotes from them, the YouTube page reader, and gap repair.

$m = kick_chat_message('App\\Events\\ChatMessageEvent', ['id' => 'k1', 'content' => 'gg [emote:37226:KEKW] nice', 'created_at' => '2026-11-27T21:00:00+00:00', 'sender' => ['username' => 'Some_One']]);
same([$m['id'], $m['ts'], $m['text']], ['k1', strtotime('2026-11-27T21:00:00+00:00'), 'Some_One: gg KEKW nice'], 'Kick message');
same($m['emotes'], ['KEKW' => 'https://files.kick.com/emotes/37226/fullsize'], 'Kick emote as a picture link');
same(kick_chat_message('App\\Events\\GiftedSubscriptionsEvent', ['gifted_usernames' => ['a', 'b', 'c', 'd', 'e'], 'gifter_username' => 'Big'])['text'], '** Big is gifting 5 subs', 'Kick gift subs');
same(kick_chat_message('App\\Events\\StreamHostEvent', ['host_username' => 'Other', 'number_viewers' => 250])['text'], '** Other is raiding with 250 viewers', 'Kick raid');
same(kick_chat_message('App\\Events\\UserBannedEvent', ['user' => ['username' => 'troll'], 'duration' => 10])['text'], '** troll was timed out for 600s', 'Kick timeout');
same(kick_chat_message('App\\Events\\PinnedMessageCreatedEvent', ['x' => 1]), null, 'other events ignored');
same(kick_chat_message('App\\Events\\ChatMessageEvent', ['id' => 'k2', 'content' => 'hi', 'sender' => ['username' => '<b>x</b>']])['text'], 'bxb: hi', 'odd names cleaned');

$y = youtube_chat_message(['liveChatTextMessageRenderer' => ['id' => 'y1', 'timestampUsec' => '1795800000000000', 'authorName' => ['simpleText' => '@Some Handle.x'],
    'message' => ['runs' => [['text' => 'hello '], ['emoji' => ['emojiId' => '😀']], ['text' => ' '], ['emoji' => ['isCustomEmoji' => true, 'shortcuts' => [':_wave:'], 'image' => ['thumbnails' => [['url' => 'https://yt3.ggpht.com/abc=w24']]]]]]]]]);
same([$y['id'], $y['ts'], $y['text']], ['y1', 1795800000.0, 'Some_Handle.x: hello 😀 _wave'], 'YouTube message with emoji');
same($y['emotes'], ['_wave' => 'https://yt3.ggpht.com/abc=w24'], 'YouTube channel emoji as a picture');
same(youtube_chat_message(['liveChatPaidMessageRenderer' => ['id' => 'y2', 'authorName' => ['simpleText' => 'Fan'], 'purchaseAmountText' => ['simpleText' => '€5.00'], 'message' => ['runs' => [['text' => 'love it']]]]])['text'],
    '** Fan sent a Super Chat (€5.00): love it', 'Super Chat');
same(youtube_chat_message(['liveChatSponsorshipsGiftPurchaseAnnouncementRenderer' => ['id' => 'y3', 'header' => ['liveChatSponsorshipsHeaderRenderer' => ['authorName' => ['simpleText' => 'Gifter'], 'primaryText' => ['runs' => [['text' => 'Gifted '], ['text' => '10'], ['text' => ' memberships']]]]]]])['text'],
    '** Gifter is gifting 10 memberships', 'gifted memberships');
same(youtube_chat_message(['liveChatViewerEngagementMessageRenderer' => []]), null, 'other items ignored');

$d = temp_dir() . '/e';
mkdir($d);
emotes_merge($d, ['KEKW' => 'https://files.kick.com/emotes/37226/fullsize', 'Kappa' => '25', 'bad' => 'https://evil.example/x.png']);
same(emotes_load($d), ['twitch' => ['Kappa' => '25'], 'extra' => ['KEKW' => 'https://files.kick.com/emotes/37226/fullsize']], 'links and ids sorted, other sites dropped');

same(json_object_after('<script>var ytInitialPlayerResponse = {"a":{"b":"x};var y"},"c":[1,2]};var meta = 1;</script>', 'ytInitialPlayerResponse'), ['a' => ['b' => 'x};var y'], 'c' => [1, 2]], 'page JSON with tricky text inside');
same(json_object_after('nothing here', 'ytInitialPlayerResponse'), null, 'no JSON');

// Gap repair: parts at 0 s (20:00:00), 100 s (20:03:20, so 100 s missing: an ad break) and 200 s (20:05:05, 5 s missing).
$meta = ['stream_started' => '2026-11-27 19:59:50', 'timeline' => [[0, '2026-11-27 20:00:00.000'], [100, '2026-11-27 20:03:20.000'], [200, '2026-11-27 20:05:05.000']]];
$holes = gaps_find($meta, 300);
same(count($holes), 3, 'three holes: the start, an ad break, a drop');
same([$holes[0]['after'], $holes[0]['to'] - $holes[0]['from']], [-1, 10.0], 'the 10 s before the recorder joined');
same([$holes[1]['after'], $holes[1]['at'], $holes[1]['to'] - $holes[1]['from']], [0, 100.0, 100.0], 'the ad break after part 1');
same([$holes[2]['after'], $holes[2]['to'] - $holes[2]['from']], [1, 5.0], 'the drop after part 2');
$meta2 = $meta; $meta2['stream_started'] = '2026-11-27 19:00:00';
same(gaps_find($meta2, 300)[0]['after'], 0, 'a start more than 10 minutes late is not filled');
$fills = [['after' => -1, 'at' => 0.0, 'from' => $holes[0]['from'], 'seconds' => 10.0], ['after' => 0, 'at' => 100.0, 'from' => $holes[1]['from'], 'seconds' => 99.5]];
same(gaps_shift(50, $fills), 60.0, 'a moment in part 1 moves by the start fill');
same(gaps_shift(150, $fills), 259.5, 'a moment in part 2 moves by both');
same(gaps_new_timeline($meta['timeline'], $fills), [[0, '2026-11-27 19:59:50.000'], [10.0, '2026-11-27 20:00:00.000'], [110.0, '2026-11-27 20:01:40.000'], [209.5, '2026-11-27 20:03:20.000'], [309.5, '2026-11-27 20:05:05.000']], 'new timeline with the fills');
$map = chat_mapper(['timeline' => gaps_new_timeline($meta['timeline'], $fills)]);
same($map(local_ms('2026-11-27 20:02:00')), 130.0, 'chat during the old ad break now has its own place in the video');

$master = "#EXTM3U\n#EXT-X-MEDIA:TYPE=VIDEO,GROUP-ID=\"chunked\",NAME=\"1080p60\"\n#EXT-X-STREAM-INF:BANDWIDTH=6000000,VIDEO=\"chunked\"\nhttps://v/chunked/index.m3u8\n"
    . "#EXT-X-MEDIA:TYPE=VIDEO,GROUP-ID=\"480p30\",NAME=\"480p\"\n#EXT-X-STREAM-INF:BANDWIDTH=1400000,VIDEO=\"480p30\"\nhttps://v/480p30/index.m3u8\n";
same(gaps_pick($master, '1080p60 (source)'), 'https://v/chunked/index.m3u8', 'source quality');
same(gaps_pick($master, '480p'), 'https://v/480p30/index.m3u8', 'same quality by name');
same(gaps_pick($master, '720p60'), 'https://v/chunked/index.m3u8', 'unknown quality: the best');

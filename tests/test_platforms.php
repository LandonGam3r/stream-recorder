<?php
// Streamer keys: Twitch names stay as they were, Kick and YouTube get a prefix that a Twitch name can't have.

same(platform_parse('SomeStreamer')['key'] ?? null, 'somestreamer', 'Twitch name, lower-cased');
same(platform_parse('somestreamer')['platform'] ?? null, 'twitch', 'bare name is Twitch');
same(platform_parse('ab'), null, 'Twitch name too short');
same(platform_parse('kick-Some_One')['key'] ?? null, 'kick-some_one', 'Kick key');
same(platform_parse('kick:xqc')['key'] ?? null, 'kick-xqc', 'kick: form');
same(platform_parse('youtube-LofiGirl')['key'] ?? null, 'youtube-lofigirl', 'YouTube handle, lower-cased');
same(platform_parse('yt:@Some.Handle')['key'] ?? null, 'youtube-some.handle', 'yt: with @ and a dot');
same(platform_parse('youtube-UCSJ4gkVC6NrvII8umztf0Ow')['key'] ?? null, 'youtube-UCSJ4gkVC6NrvII8umztf0Ow', 'channel id keeps its case');
same(platform_parse('youtube-..'), null, 'no dots-only handle');
same(platform_parse('youtube-../etc'), null, 'no path in a handle');
same(platform_parse('kick-a/b'), null, 'no slash in a Kick name');

same(platform_from_input('https://www.twitch.tv/SomeStreamer'), 'somestreamer', 'Twitch link');
same(platform_from_input('twitch.tv/somestreamer/videos'), 'somestreamer', 'Twitch link with a path');
same(platform_from_input('https://kick.com/Trainwreckstv'), 'kick-trainwreckstv', 'Kick link');
same(platform_from_input('https://www.youtube.com/@LofiGirl/streams'), 'youtube-lofigirl', 'YouTube @ link');
same(platform_from_input('https://youtube.com/channel/UCSJ4gkVC6NrvII8umztf0Ow'), 'youtube-UCSJ4gkVC6NrvII8umztf0Ow', 'YouTube channel link');
same(platform_from_input('@LofiGirl'), 'youtube-lofigirl', '@handle means YouTube');
same(platform_from_input('not a name!'), null, 'junk is refused');

// Every key the parser makes is accepted by the dashboard's check, and every Twitch name too.
foreach (['somestreamer', 'kick-xqc', 'kick-some_one', 'youtube-lofigirl', 'youtube-some.handle', 'youtube-UCSJ4gkVC6NrvII8umztf0Ow'] as $k) {
    check(preg_match(STREAMER_KEY_RE, $k) === 1, "STREAMER_KEY_RE accepts $k");
    same(platform_parse($k)['key'] ?? null, $k, "key $k parses to itself");
}
foreach (['../x', 'kick-', 'youtube-a/b', 'a b', ''] as $k) check(preg_match(STREAMER_KEY_RE, $k) !== 1, "STREAMER_KEY_RE refuses '$k'");

// Quality names: Twitch's NAME, else the resolution (Kick / YouTube).
$yt = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=854x480\nhttps://x/480.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080\nhttps://x/1080.m3u8\n";
$name = '';
same(pick_variant($yt, $name), 'https://x/1080.m3u8', 'best variant by bandwidth');
same($name, '1080p', 'named by resolution');
$tw = "#EXTM3U\n#EXT-X-MEDIA:TYPE=VIDEO,GROUP-ID=\"chunked\",NAME=\"1080p60 (source)\"\n#EXT-X-STREAM-INF:BANDWIDTH=6000000,RESOLUTION=1920x1080,VIDEO=\"chunked\"\nhttps://t/src.m3u8\n"
    . "#EXT-X-MEDIA:TYPE=VIDEO,GROUP-ID=\"audio_only\",NAME=\"audio_only\"\n#EXT-X-STREAM-INF:BANDWIDTH=160000,VIDEO=\"audio_only\"\nhttps://t/audio.m3u8\n";
$name = '';
same(pick_variant($tw, $name), 'https://t/src.m3u8', 'Twitch source, not audio only');
same($name, '1080p60 (source)', 'Twitch name kept');

// Segments: Twitch marks real ones "live"; Kick / YouTube give no title and are never taken for ads.
[$segs, $ended] = parse_media_playlist("#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:10\n#EXT-X-PROGRAM-DATE-TIME:2026-11-27T10:00:00.500Z\n#EXTINF:2.000,live\nhttps://s/10.ts\n#EXTINF:2.000,Amazon\nhttps://s/ad.ts\n#EXTINF:2.0,\nhttps://s/12.ts\n#EXT-X-ENDLIST\n");
same(count($segs), 3, 'three segments');
same($segs[0]['seq'], 10, 'sequence number');
same($segs[0]['ad'], false, 'live segment');
same($segs[1]['ad'], true, 'ad segment');
same($segs[2]['ad'], false, 'untitled segment (Kick/YouTube) is not an ad');
check(abs($segs[0]['pdt'] - (strtotime('2026-11-27T10:00:00Z') + 0.5)) < 0.01, 'program date-time with milliseconds');
same($ended, true, 'end of stream');

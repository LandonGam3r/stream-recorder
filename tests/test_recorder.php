<?php
// Chat lines from Twitch's chat server, and text cleaning.

$m = format_chat_line('@display-name=Some_One;id=abc;tmi-sent-ts=1764237600000 :some_one!some_one@x PRIVMSG #chan :hello there');
same($m['text'] ?? null, 'Some_One: hello there', 'chat message');
same($m['id'] ?? null, 'abc', 'message id');
same($m['ts'] ?? null, 1764237600, 'sent time');
$m = format_chat_line("@display-name=A;id=b :a!a@x PRIVMSG #chan :\x01ACTION waves\x01");
same($m['text'] ?? null, '* A waves', '/me message');
$m = format_chat_line('@ban-duration=600;id=c :tmi.twitch.tv CLEARCHAT #chan :troll');
same($m['text'] ?? null, '** troll was timed out for 600s', 'timeout');
$m = format_chat_line('@display-name=<script>;id=d :evil!evil@x PRIVMSG #chan :x');
same($m['text'] ?? null, 'evil: x', 'odd display name falls back to the login');
same(format_chat_line(':tmi.twitch.tv 001 justinfan :Welcome'), null, 'server lines are skipped');

same(clean_text("a\x07b\r\nc \u{202E}d"), 'ab c d', 'control and direction characters removed');

// The server only fetches from the public internet (no local or private addresses, https only).
foreach (['https://127.0.0.1/x', 'https://10.1.2.3/', 'https://192.168.1.1:2083/', 'https://169.254.169.254/latest/meta-data', 'https://[::1]/', 'https://100.64.1.1/', 'https://0.0.0.0/'] as $u) {
    $x = $u; same(net_check($x), null, "refused: $u");
}
$x = 'http://8.8.8.8/'; same(net_check($x), null, 'plain http refused');
$x = 'https://user@8.8.8.8/'; same(net_check($x), null, 'user@ refused');
$x = 'https://8.8.8.8/x'; same(net_check($x), [], 'a public address is fine');

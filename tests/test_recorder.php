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

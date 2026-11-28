# Kick and YouTube Live

Besides Twitch, the recorder can record live streams on **Kick** and **YouTube**, with chat. Add them on the dashboard by pasting a link to the channel:

| Platform | Paste | Folder name |
|---|---|---|
| Twitch | `name` or `twitch.tv/name` | `name` |
| Kick | `kick.com/name` | `kick-name` |
| YouTube | `youtube.com/@handle` or `youtube.com/channel/UC…` | `youtube-handle` or `youtube-UC…` |

Everything after the recording works the same for all three: MP4 conversion, the player and text view, search, YouTube upload with moments, PC scan and AI summaries. Old Twitch recordings and streamers are not renamed.

**Test Kick / YouTube access** on the dashboard checks, from your server, whether Kick, YouTube Live and the emote services can be reached, and whether Twitch gives viewer counts.

## Kick

- **Live check and video:** through the same public channel information Kick's website uses (`kick.com/api/v2/channels/…`). Kick has no ads in the video, so nothing is cut.
- **Chat:** messages, subscriptions, gifted subs and hosts/raids, read anonymously through Kick's public chat connection. Should Kick change its public chat key, set the new one as `KICK_PUSHER_KEY` in `config.php`.
- **Viewer counts and category** are recorded like on Twitch.
- **Cloudflare:** Kick sits behind Cloudflare, which sometimes blocks data-centre addresses. If the access test shows Kick as blocked from your host, recording Kick from that server won't work; from your own computer it usually does.

## YouTube Live

- **Live check and video:** from the channel's `/live` page, the way a browser sees it; the video comes from YouTube's live HLS playlist (the best quality offered, or `QUALITY`).
- **Chat:** messages, Super Chats and memberships (new and gifted), read every few seconds the way the live chat window does.
- **A bot check:** YouTube sometimes asks servers in data centres to sign in. The access test shows *not sure* or *blocked* then, and the streamer's log says *YouTube asks this server to sign in*. That can come and go; when it stays, record that channel from your own computer instead.
- No categories (YouTube doesn't give them on that page), so category moments rules don't apply.

## What only Twitch has

- **Gap repair** from the VOD ([how-it-works.md](how-it-works.md#gap-repair)).
- **Ad-free recording with your login** ([twitch-token.md](twitch-token.md)). Kick has no video ads; on YouTube the live HLS has none.

## Good to know

Kick and YouTube are read through their public web pages, without an official API, the same way as Twitch. Each can change that at any time; when something breaks, the streamer's log says what went wrong, and an update usually follows.

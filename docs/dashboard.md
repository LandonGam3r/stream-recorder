# Using the dashboard

![Dashboard](images/dashboard.png)

## Logging in

Open the dashboard's address and paste the key from `secret/dashboard_key.txt` (in the recorder folder; the local start script also prints it). The browser stays logged in for 180 days, and a password manager can save the key.

- **New key / log out every browser:** delete both `dashboard_key.txt` and `dashboard_key.hash`. The next recorder run creates a new key.
- After logging in on all your devices you may delete `dashboard_key.txt`; the dashboard only needs the `.hash`.

## Header

- **Twitch token**: working, not working, or not set (hover for details). Only relevant if you use the [token](twitch-token.md).
- **Cron**: when the recorder last ran. It turns red when there has been no run for 3 minutes (the cron job or start script stopped).

## Tiles

One row that adapts to what's going on:
- **Recording now**: how many streamers are live.
- **Alerts**: new alerts, shown when there are any.
- **YouTube**: accounts and queue.
- **Converting / Uploading**: shown while those run.
- **Server processes**: running conversions and recorder runs. Red if two conversions ever run at once.
- **Storage**: space used, and free space.

## Disk IO

![IO graph](images/io-graph.png)

- **What it shows:** how much the account's processes read and write per second, split into recording, converting and other (your website, other tools), over 1 h, 6 h or 24 h.
- **The dashed line** is your host's limit (`IO_LIMIT_MB` in the dashboard config).
- **Details:** hover for exact numbers, or switch to *Table*.
- **Accuracy:** measured every 5 seconds from Linux's per-process disk counters. Very short processes (single web requests) can be missed, so your host's own statistics may show a little more.

## Alerts

Everything worth knowing, newest first, with the full text under *Details*:
- a stream ended, with its summary;
- Twitch token problems;
- conversion or upload problems, and successful uploads with a link;
- low disk space;
- a streamer name that doesn't exist.

There is no email: the dashboard is the place to look. "Mark all as read" is remembered per browser.

## Streamers

- **Add:** type a Twitch name or paste a twitch.tv link. With *record right away* ticked, recording is switched on immediately.
- **Switch on/off:** the switch in front of each name.
  - **Switching off a live streamer** asks first; the recording so far is kept and handled like any finished recording (chat sync, MP4, upload, alert).
  - **Switching on during a stream** starts recording within a minute.
- **Upload to YouTube:** choose an account, or no upload ([youtube.md](youtube.md)).
- **Your Twitch login:**
  - *Auto*: use your token only on channels where your account is ad-free.
  - *Always*: always use it.
  - *Never*: record anonymously.
- **Remove streamer:** available when switched off. Recordings stay until you delete them.
- **Order:** switched-on streamers first (live ones on top), switched-off ones below. Click a name bar to fold a card to one line.

Badges: **Recording**, **Waiting for stream** (on, streamer offline), **Not found on Twitch** (check the spelling), **Off**, **Stopping…** (switched off, last recording being wrapped up).

## Recordings

Each recording shows its title, date, length, files, size and status. Running conversions and uploads show a progress bar with the time left.

- **▶ Play** opens the player in a new tab:
  - **Download to this PC, then play** (Chrome/Edge):
    - The first time, pick a folder (e.g. Videos); it's remembered.
    - Each recording gets a subfolder with `video.mp4`, `chat.txt` and `meta.json`. It downloads in pieces with automatic retries, can be paused and continues where it stopped, then plays from your PC.
  - **Stream from the server**: plays right away, but can stutter on long or high-quality streams.
  - Other browsers get plain download links.
- **Delete** asks for confirmation, then removes the whole recording folder. It's not possible while that stream is still recording or converting.

![Player](images/player.png)

The player: click a chat timestamp to jump there; *Chat sync ±* nudges chat if it feels early or late; **Pop out chat** opens it in its own window for a second screen. Keys: Space, ←/→, F (fullscreen), C (chat window).

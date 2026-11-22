# Install on a web server

Works on ordinary shared hosting (cPanel, DirectAdmin, Plesk…) as well as on a VPS. You need PHP 8.0+ with `curl` and `openssl`, cron jobs, and PHP's `exec()` allowed.

The examples use cPanel and the home folder `/home/you/`. Replace `you` with your account name (cPanel shows the home directory in the sidebar).

## 1. The recorder

1. In the **File Manager**, create a folder `twitch-recorder` in your **home folder**: next to `public_html`, **not inside it**, so recordings and secrets can never be downloaded from the web.
2. Upload the contents of `server/` into it: `record.php` and `config.example.php`.
3. Optional: copy `config.example.php` to `config.php` and change settings. Every setting is explained in the file and in [configuration.md](configuration.md). Common ones:
   - `QUALITY`: `best` ≈ 2.8 GB per hour at 1080p60, `480p30` ≈ 0.6 GB per hour.
   - `TIMEZONE`: e.g. `'Europe/Amsterdam'`.
   - `CONVERT_SPEED`: `4` on shared hosting with an IO limit (see step 3).

## 2. The cron job

cPanel → **Cron Jobs** → Common settings "Once Per Minute" (`* * * * *`). Command:

```
REC_KEY=YourLongPassphrase123 /usr/bin/php -q /home/you/twitch-recorder/record.php >> /home/you/twitch-recorder/cron_output.txt 2>&1
```

- **The PHP path matters.** On many hosts the plain `php` in cron is an old version.
  - Use the full path of PHP 8, e.g. `/opt/alt/php83/usr/bin/php` (CloudLinux) or `/usr/local/bin/php`. Your host's documentation or support can tell you which.
  - If `cron_output.txt` shows `Parse error` or `unexpected '?'`, cron is using an old PHP.
- **`REC_KEY`** is a passphrase of your choice (16+ letters and digits, no spaces, no `%`). It encrypts the optional Twitch and YouTube tokens. It lives only in the cron line, never in a file next to the tokens.
  - Keep a copy somewhere safe: if you change it, stored tokens have to be set up again.
  - You can leave it out if you don't use the token or YouTube.
- One line for everything: the recorder reads its streamer list from `channels.json`, which you edit on the dashboard.

Within a minute, `twitch-recorder/` contains `recordings/`, `secret/` (with your dashboard key) and `channels.json`.

## 3. ffmpeg (for MP4)

Each recording's first log lines say `MP4 conversion: ffmpeg found at …` or `NOT available - <reason>`.

If your host has no ffmpeg:
1. Download the Linux static build from https://johnvansickle.com/ffmpeg/ (`ffmpeg-release-amd64-static.tar.xz`).
2. Extract it on your PC (e.g. with 7-Zip) and upload just the `ffmpeg` file to `twitch-recorder/bin/`.
3. Set its permissions to `755`.

**Disk IO limits:** many shared hosts limit disk traffic per account (often a few MB/s; cPanel → *Resource Usage* shows it).
- A conversion reads and writes the whole recording, so by default conversions only start while nothing is recording (`CONVERT_WHEN_IDLE`), at the lowest priority.
- Set `CONVERT_SPEED` to stay under the limit: with ~7 MB/s, `4` works for 1080p60.
- Set the same limit as `IO_LIMIT_MB` in the dashboard's `config.php` to see it in the IO graph.

## 4. The dashboard

1. Create a folder in `public_html` with a name that's hard to guess, e.g. `rec-7f3k9q`.
2. Upload `dashboard/index.php` and `dashboard/player.html` into it.
3. Open `https://your-site/rec-7f3k9q/`.
   - The dashboard finds the recorder by itself when it's at `/home/you/twitch-recorder` (two levels up from the dashboard folder).
   - Elsewhere: copy `config.example.php` to `config.php` next to `index.php` and set `RECORDER_DIR`.
4. Log in with the key from `twitch-recorder/secret/dashboard_key.txt`. Your browser stays logged in for 180 days. Once all your browsers are logged in you may delete `dashboard_key.txt` (keep `dashboard_key.hash`).

## 5. Add streamers

On the dashboard: type a Twitch name (or paste a twitch.tv link), tick *record right away*, click **Add streamer**. Done. See [dashboard.md](dashboard.md).

Optional extras: [ad-free recording with your Twitch login](twitch-token.md) and [YouTube upload](youtube.md).

## Updating

Replace `record.php`, `index.php` and `player.html` with the new versions. Your settings (`config.php`), streamers, recordings and keys are untouched. It's safe while recording: the next run uses the new file.

## Stopping

Switch streamers off on the dashboard, or remove the cron job to stop everything.

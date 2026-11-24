# Configuration

Everything works without configuration. To change a setting, copy the example file to `config.php` in the same folder and edit it; anything you leave out keeps its default. `config.php` is never part of an update, so your settings survive.

Streamers and their per-streamer settings (on/off, YouTube account, Twitch login) are **not** in a config file: you manage them on the dashboard, which stores them in `server/channels.json`. The same goes for YouTube moments rules (`server/moments.json`).

## Recorder: `server/config.php`

Copy `server/config.example.php`.

| Setting | Default | What it does |
|---|---|---|
| `QUALITY` | `'best'` | `best` (source) or a rendition like `1080p60`, `720p60`, `720p30`, `480p30`, `360p30`, `160p30`, `audio_only`. Falls back to `best` if a channel doesn't offer it. |
| `RECORD_CHAT` | `true` | Also record chat. |
| `TIMEZONE` | `''` | Time zone for logs, folder names and chat times, e.g. `'Europe/Amsterdam'`. `''` = the server's. |
| `MIN_RUNTIME` | `50` | Seconds a run records before it hands over to the next run. |
| `MAX_RUNTIME` | `300` | Safety cap for one run when no next run shows up. `0` = one run records the whole stream (only if your host allows long processes). |
| `POLL_INTERVAL` | `2` | Seconds between playlist refreshes while recording. |
| `OFFLINE_CHECK_INTERVAL` | `10` | While a switched-on streamer is offline, check every N seconds, so recording starts within ~N seconds. `0` = once a minute (less load). |
| `TOKEN_CHECK_INTERVAL` | `3600` | How often the optional Twitch token is checked. |
| `AUTO_CONVERT` | `true` | Convert finished recordings to MP4. |
| `FFMPEG_PATH` | `''` | Path to ffmpeg. `''` = the system's, or `bin/ffmpeg` next to `record.php`. |
| `DELETE_TS_AFTER_CONVERT` | `true` | Delete the `.ts` parts after a successful, length-checked conversion. |
| `CONVERT_WHEN_IDLE` | `true` | Start conversions and YouTube uploads only while nothing records. |
| `CONVERT_SPEED` | `0` | Conversion speed cap as a multiple of real time (`0` = no cap). `4` suits a ~7 MB/s host IO limit. |
| `CONVERT_ATTEMPTS` | `3` | Retries for a conversion that fails or is stopped. |
| `CONVERT_FRAGMENTED` | `true` | Fragmented MP4: flat ~50 MB memory however long the stream. |
| `YOUTUBE_PRIVACY` | `'private'` | `private`, `unlisted` or `public` (Google forces private for unaudited apps, see [youtube.md](youtube.md)). |
| `YOUTUBE_CATEGORY` | `'20'` | YouTube category id (20 = Gaming). |
| `UPLOAD_BUDGET` | `45` | Seconds of uploading per run; the upload continues next run. |
| `UPLOAD_CHUNK_MB` | `16` | Upload piece size. |
| `DEBUG` | `false` | Log every run, including routine offline checks and handovers. |

Not in `config.php` on purpose:
- **`REC_KEY`** (the passphrase for stored tokens) goes in the cron line or the start script's environment. That way the key that unlocks your tokens is never stored next to them.

## Dashboard: `dashboard/config.php`

Copy `dashboard/config.example.php` (next to `index.php`).

| Setting | Default | What it does |
|---|---|---|
| `RECORDER_DIR` | `''` | The folder with `record.php`. `''` = automatic: the `RECORDER_DIR` environment variable, `../server`, or `twitch-recorder` / `stream-recorder` two levels up. |
| `COOKIE_DAYS` | `180` | How long a browser stays logged in. |
| `IO_LIMIT_MB` | `0` | Your host's IO limit in MB/s, drawn in the IO graph (`0` = no line). |
| `DOWNLOAD_LIMIT_MB` | `'auto'` | Speed cap when the player downloads a recording to your PC. `'auto'` = 80% of `IO_LIMIT_MB` (no cap when that's 0), `0` = no cap, or a number. |
| `MAX_FAILS` | `10` | Wrong keys per visitor per hour before that visitor is blocked for the hour. |
| `FORCE_HTTPS` | `'auto'` | Redirect to HTTPS and send HSTS. `'auto'` = for real domain names, not for localhost, IP addresses or local names. `true`/`false` = always/never. |
| `TIMEZONE` | `''` | Time zone for the dashboard. `''` = the recorder's (it reports it in `recordings/.settings.json`). |
| `ICON_FILE` | `''` | Your own page icon (png/jpg/gif/webp/ico, max 300 KB), full path or relative to the dashboard folder. |

## Files you can drop in

| File | Where | Effect |
|---|---|---|
| `secret/token_setup.txt` | recorder folder | Your Twitch token; encrypted and deleted by the next run ([twitch-token.md](twitch-token.md)). |
| `secret/youtube_setup_<name>.json` | recorder folder | A YouTube account from the PC uploader; encrypted and deleted by the next run ([youtube.md](youtube.md)). |
| `STOP-CONVERSIONS.txt` | recorder folder | Emergency brake: stops a running conversion, empties the queue, no new conversions while it exists. |
| `convert-again.txt` | a recording's folder | Converts that recording again (fresh attempt count). |
| `bin/ffmpeg` | recorder folder | Your own ffmpeg build. |

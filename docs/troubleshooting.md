# Troubleshooting

Start with the dashboard: the **Cron** chip, the **Alerts**, and each streamer's **Recent log**. For more detail, set `DEBUG` to `true` in `server/config.php`. The full logs are in the recorder folder:

| File | Contains |
|---|---|
| `cron_output.txt` | PHP errors (syntax errors, crashes) |
| `last_run.txt` | One line per run: proof that cron runs, and which PHP |
| `recordings/.system.log` | The manager: token checks, setup files |
| `recordings/<streamer>/recorder.log` | Everything about that streamer |
| `recordings/<streamer>/<recording>/conversion.log` | ffmpeg's warnings for that conversion |

## Common problems

**Cron chip red / nothing happens**
- Check `last_run.txt`. If it's empty or old, the cron job doesn't run: check the command, and that the path to `record.php` is right.
- If `cron_output.txt` shows `Parse error` / `unexpected '?'`, cron uses an old PHP. Use the full path to PHP 8 in the cron line.
- `PHP cURL extension is required`: enable `curl` for that PHP version.

**Alert "Recorder cannot start recordings"**
- Your host has disabled PHP's `exec()`, so the manager can't start runs (and ffmpeg can't run either). Ask your host to allow it, or use one cron line per streamer instead: `... record.php STREAMERNAME ...` (same command with the streamer's Twitch name at the end). Each such line records that streamer and puts it on the dashboard's list.

**"Recorder folder not found" on the dashboard**
- Set `RECORDER_DIR` in `dashboard/config.php` to the folder containing `record.php`.

**Streamer shows "Not found"**
- Check the spelling: it's the part after `twitch.tv/`. Remove the streamer and add the right name.

**Log signals**

| Message | Meaning |
|---|---|
| `Took over recording (waited Ns)` | Normal handover between runs. |
| `GAP: missed N segment(s)` | Video was lost: a run started too late or Twitch didn't deliver. |
| `WARNING: previous run did not exit cleanly` | The host killed a run (it's counted under *Cron runs → killed*). |
| `Auto: ...` | Whether your Twitch login is used for this stream, and why. The table in [twitch-token.md](twitch-token.md#why-isnt-my-account-used-on-a-channel-im-subscribed-to) explains each case. |
| `MP4 conversion: NOT available - ...` | No ffmpeg: see the reason, and [install-webserver.md](install-webserver.md#3-ffmpeg-for-mp4). |
| `Not enough disk space to convert` | Free some space; it retries every 30 minutes. |
| `ffmpeg is gone ... without reporting back` | ffmpeg's watcher was stopped; the result is checked anyway and kept if complete. |
| `MP4 is SHORTER than the recording` | Both files are kept; `conversion.log` says why. |

**Conversions are slow or the website gets sluggish during conversions**
- Your host's IO limit is being reached. Set `CONVERT_SPEED` (e.g. `4`) and keep `CONVERT_WHEN_IDLE` on. Check with the dashboard's IO graph.

**A conversion keeps failing**
- Create `STOP-CONVERSIONS.txt` in the recorder folder to stop everything, look at `conversion.log`, delete the file again, and put `convert-again.txt` in the recording's folder to retry.
- The `.ts` parts are never deleted unless the MP4 checked out. You can also convert on your PC: `ffmpeg -i video.ts -c copy video.mp4`.

**The player can't download to my PC**
- Saving into a folder needs Chrome or Edge. Other browsers get plain download links.
- If a download was interrupted, click *Download* again: it continues where it stopped.

**Too many redirects on the dashboard**
- Something between the browser and PHP turns HTTPS into HTTP without saying so. The dashboard recognises `X-Forwarded-Proto` and Cloudflare's `CF-Visitor` headers. If your setup sends neither, set `FORCE_HTTPS` to `false` in `dashboard/config.php` and enforce HTTPS in your host's settings instead.

**Locked out after wrong keys**
- Wait an hour, or delete `secret/dashboard_fails.json` in the recorder folder.

**Twitch changed something**
- The recorder uses the same internal requests as Twitch's web player. If Twitch changes those, recording stops with errors in the log, and the code needs an update.

**Kick or YouTube don't record from the server**
- Press **Test Kick / YouTube access** on the dashboard. *Blocked* or *not sure* means the site refuses or questions your server (Kick's Cloudflare, YouTube's bot check), common for data-centre addresses. Recording that channel from your own computer usually works. See [platforms.md](platforms.md).

**Something else is off**
- **Setup check** in the dashboard's header lists what the cron job's PHP can do and what's missing, with what to do about it.

# How it works

```mermaid
flowchart LR
    cron["cron / start-local.sh<br/>every minute"] --> mgr["record.php<br/>(manager)"]
    mgr -->|"one run per<br/>switched-on streamer"| run["record.php &lt;streamer&gt;<br/>(recording run)"]
    mgr --> io["IO sampler"]
    mgr --> conv["conversions &amp;<br/>YouTube uploads"]
    run <-->|"video + chat"| twitch[(Twitch / Kick /<br/>YouTube)]
    run --> files[("recordings/")]
    conv -->|"ffmpeg, detached"| files
    mgr --> gaps["gap repair<br/>(Twitch VOD)"] --> files
    mgr --> ai["AI jobs<br/>(Claude)"] --> files
    conv --> yt[(YouTube)]
    dash["dashboard<br/>index.php"] -->|"reads"| files
    dash -->|"channels.json"| mgr
    you(("you")) --> dash
```

## The pieces

- **`server/record.php`**: the recorder, with its helpers next to it:
  - `platforms.php` (Kick and YouTube: live check, video, chat), `streamdata.php` (viewers, emotes, bookmarks, search), `archive.php` (storage rules), `gaps.php` (gap repair), `moments.php`, `scan.php`, `summary.php` and `ai.php` (summary extras, clips, questions, check-ins, digest).
  - **Manager** (started every minute without arguments): reads the streamer list, starts a recording run for each switched-on streamer, and does the background work (token check, conversions, uploads, IO sampling). Each piece runs as its own short process.
  - **Recording run** (`record.php <streamer>`): checks whether the streamer is live and records video and chat.
- **`dashboard/index.php`**: the web interface, plus a small JSON API used by the page itself. It never talks to Twitch; it reads the recorder's files and writes only `channels.json` and deletions.
- **`dashboard/scan.html`** and **`pc-tools/scan/scan.html`**: the same PC scan page; it runs speech recognition and translation in your browser. From the dashboard it gets the sound from the server; opened from disk it reads a recording folder on your PC ([pc-scan.md](pc-scan.md)).
- **`dashboard/text.html`**: the text view of a recording (summary, spoken words, chat, moments, Ask Claude).
- **`dashboard/player.html`** and **`pc-tools/player/player.html`**: the same player file, served by the dashboard or opened from disk.

## Recording in relays

Many hosts stop processes that run for a long time, and cron only starts things once a minute. So instead of one process per stream, runs take turns:

1. A run records for at least `MIN_RUNTIME` (50 s).
2. The next minute's run prepares everything while the first is still recording: live check, playlist, chat connection. Then it signals that it's ready.
3. The first run finishes its current segment and exits; the waiting run takes over within ~2 seconds.

Video segments and chat messages seen by both are de-duplicated, so the recording is continuous. Tested over 160+ handovers without a gap. Locks (`flock`) guarantee one recording run and at most one waiting successor per streamer. If a run is killed, the next one notices and logs it.

The playback session is reused between runs (~20 min), so to Twitch it looks like one viewer, and pre-roll ads aren't triggered on every run.

## Video

- **Source:** Twitch's HLS playlist (`.ts` segments) via the same requests its web player makes.
- **Ads:** ad segments (titled differently from `live`) are dropped.
- **Parts:** video is written to `parts/001.ts`, `002.ts`, … A new part starts wherever timestamps can jump: a new session, an ad break, a gap.
- **Chat sync:** the wall-clock time of every part start is kept (`meta.json` → `timeline`), so chat stays in sync after jumps.

## Chat

- **Connection:** anonymous connection to Twitch chat over WebSocket on port 443. Port 6667 is often blocked on shared hosting.
- **What's logged:** messages, subscriptions, raids, timeouts and bans, as `[time] user: message` in `chat.txt`, de-duplicated by message id.
- **Sync info:** at the end of the stream the sync info is written into the first line of `chat.txt` (`#META {...}`), so `video.mp4` + `chat.txt` alone are enough to play it back anywhere.

## Gap repair

A live recording can have holes: an ad break shown to you (without the ad-free token), a piece the server missed while busy, or a start a little after the stream began. Twitch keeps the whole stream as a VOD, so after the stream ends the recorder fills those holes from it (`GAP_REPAIR`, Twitch only):

1. **Find:** holes of 4 seconds or more come from `meta.json` → `timeline` (each part's wall-clock start and length), plus up to 10 minutes missed at the start.
2. **Wait for the VOD:** up to an hour for Twitch to publish it. Streamers who don't keep VODs, or delete them, just get no repair.
3. **Fill:** the matching VOD segments are saved as `parts/NNN_fill.ts`, right after the part before the hole, so the conversion joins everything in order. Chat times and moments after a fill are shifted to match.
4. **Convert** as usual. The MP4 conversion waits while a repair runs (at most 6 hours, then it goes ahead without).

The recording's line on the dashboard says how many holes were filled and how much time was added.

## Conversion

- **Method:** ffmpeg's concat demuxer joins the parts end to end with `-c copy`: lossless, no re-encoding.
- **Fragmented MP4:** keeps memory flat (~50 MB) however long the stream; a normal MP4 builds its index in memory until the end.
- **Detached:** ffmpeg runs separately from the PHP run that started it, because hosts that stop long PHP processes would otherwise take the conversion down too. Each later run only checks on it, and when it's done checks the result.
- **Checks before deleting anything:** the MP4's duration must match the recording (≥ 90 %); only then are the `.ts` parts deleted. A shorter MP4 keeps both and raises an alert.
- **Polite to the host:** runs at the lowest CPU/IO priority, only while nothing records (`CONVERT_WHEN_IDLE`), optionally speed-capped (`CONVERT_SPEED`), and only after a disk-space check.

## Files

```
server/
  record.php, config.php
  moments.php                YouTube moments scanner (used by record.php and the dashboard)
  scan.php                   PC scan: prepares the sound, holds uploads, writes subtitles
  summary.php                AI summary: pictures, sends to Claude (Message Batches), fetches the result
  platforms.php, streamdata.php, archive.php, gaps.php, ai.php   see "The pieces"
  summary_settings.json      AI summary settings (the dashboard edits them)
  channels.json              streamer list (the dashboard edits it)
  moments.json               YouTube moments rules (the dashboard edits them)
  speech-lib/<version>/      pinned copy of the PC scan's speech library + manifest.json (checksums)
  recordings/
    .notifications.json      alerts
    .io_history.json         IO graph data (24 h, per minute)
    .convert_queue.json, .upload_queue.json, .system.log, .settings.json
    .digests/                weekly digests
    <streamer>/
      recorder.log (+ recorder.log.1 after rotation), state.json
      2026-11-17_150000_<streamid>/
        video.mp4            after conversion (parts/NNN.ts while recording)
        chat.txt             chat, first line = sync info
        meta.json            title, times, timeline, title/category changes, moments, YouTube link
        summary.txt          end-of-stream summary
        scan.json            PC-scan state (only when asked or scanned)
        ai_summary.md, ai_summary.json   AI summary and its state/cost (ai_frames/ while it's made)
        viewers.txt          viewer count per minute; emotes.json: emote pictures seen in chat
        bookmarks.json       your bookmarks
        ai_extras.json, thumbnail.jpg, clips.json, clips/, ai_ask.json, checkins.json   AI extras
        transcript.json      what was said, with times and translation (PC scan)
        subtitles.vtt, subtitles.<lang>.vtt, chat_translation.<lang>.json
        audio/partNNN.ogg    sound pieces for a running PC scan (deleted when done)
  secret/                    dashboard key, encrypted tokens (never web-reachable)
  last_run.txt, cron_output.txt
```

`recordings/` and `secret/` get a `.htaccess` that denies web access, as a second line of defence in case the recorder folder ends up inside a web root.

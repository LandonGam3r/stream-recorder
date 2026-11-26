# Changelog

All notable changes to Stream Recorder™. Dates are release dates; versions follow [semantic versioning](https://semver.org/).

## [1.7.0] - 2026-11-26

### Added
- **Subtitle style in the player.** Drag the subtitles anywhere on the picture and drag their corner to resize them. **Aa** sets the size, font, text colour, outline (thin, thick, shadow) and background (none, per line or box, with colour and opacity). Remembered per browser, works in full screen. The player now draws the subtitles itself instead of the browser.
- **AI summary: Read in a window.** Opens the summary large over the dashboard, with **Download** to save it as a text file.

### Fixed
- **"Download to this PC" got slower and slower on big recordings.**
  - **Cause:** Chrome copies the whole file every time something is added to it. The player saved every 256 MB into `video.mp4`, so at 5 GB each save meant copying 5 GB first, and at 20 GB, 20 GB. Measured: each save took about 4 seconds longer for every GB already downloaded.
  - **Fix:** the video is now saved as separate 256 MB pieces, joined into `video.mp4` once at the end, so the speed stays the same throughout. A download started with an older version continues as usual.
- **A YouTube upload slowed down a PC download of the same server.** Both read big files and share the host's IO limit. The upload now waits while a recording is being downloaded to a PC (it continues by itself afterwards, and the log says so).
- **AI summary cut off on long streams.** Claude could write at most 4,000 tokens, which a detailed summary of a 6-hour stream outgrew. It now may write up to 16,000, with a slightly more compact timeline (about one point per 10–15 minutes). Use **Summarize again** for a summary that was cut off.

## [1.6.1] - 2026-11-26

### Fixed
- **PC scan looked stuck on long recordings.** While a piece is being recognised, the scan page now shows a running clock ("This piece: 3 min 20 s so far"). The time left only appears after a piece finished in the same session, so a scan that continues from an earlier one had no sign of life for minutes.
- **Sound labels in subtitles.** Labels like "(upbeat music)", "(laughs)" or "*Applaus*" are now removed, not only ones starting with "music". With word timing a label could also be split over two lines ("(upbeat" … "music)"); those words are left out too.

## [1.6.0] - 2026-11-26

### Added
- **AI summary of a stream** ([docs/ai-summary.md](docs/ai-summary.md)): a written overview to catch up on a missed stream. It has a headline, a short overview, a timeline, highlights, the chat's mood, and announcements. On the dashboard, **Summary** at a recording opens it; every time in it opens the player at that point.
  - **Claude (default):** the server sends what the streamer said (from the PC scan), the chat, categories, moments and a picture every few minutes to Anthropic's Message Batches API (half price; result within minutes), and collects the result on a later cron run.
    - **Cost:** well under 1 cent per stream with Claude Haiku 5.5; Claude Sonnet 5.5 is optional. The cost is shown per summary, and in total on the card.
    - **Automatic:** after each stream (after the MP4 conversion and your PC-scan choice), or by hand with **Summarize**.
  - **Free on your PC:** without an API key, the scan page writes the summary with Chrome's built-in AI (Chrome/Edge 138+), in 10-minute parts and without pictures. The stand-alone `pc-tools/scan.html` writes `ai_summary.md` into the folder.
  - **AI summaries card:**
    - **Key:** paste the API key; it's stored encrypted by the next cron run, like the Twitch token, and checked daily.
    - **Settings:** model, automatic on/off, pictures on/off.
    - **Help:** step-by-step setup, cost and privacy notes.
- **Player:** opening it with `#t=<seconds>` starts at that point (used by the summary's times).
- **Footer** on the dashboard with © year and a ↑ back-to-the-top button.

### Changed
- **The dashboard header stays at the top while scrolling.** On phones it's compact, about two short rows.
- **The secrets check in the GitHub workflow** also looks for Anthropic API keys.
- **pc-tools/README.md:** the uploader takes the description from `meta.json` (`summary.txt` hasn't been used since 1.5.0).

## [1.5.1] - 2026-11-26

### Fixed
- **PC scan failed to load the speech model** ("no available backend found … Failed to fetch dynamically imported module …/ort-wasm-simd-threaded.jsep.mjs"). The speech engine treats a file location as a folder and dropped the `?lib=` of the server copy introduced in 1.5.0. It now gets each file's full address. The checksum lock still applies.
- **Player: "Download to this PC, then play" could hang without a message** when Chrome couldn't use the saved download folder (moved, renamed, or access not confirmed after a restart). It now says what's wrong and asks you to pick the folder again with *Change folder*. While Chrome waits for you to allow folder access, the player says so. A recording that's already on the PC is still not downloaded again; only the small files (chat, subtitles, translations, text version) are refreshed.
- **Phone layout:**
  - **Recordings list:** a recording's status and buttons now go under its title instead of squeezing the title to one letter per line.
  - **YouTube moments card:** a long recording title in the test picker no longer makes the card wider than the screen.
- **Browser console:** removed `bluetooth` from the Permissions-Policy header, which Chrome flagged as unrecognised.

## [1.5.0] - 2026-11-25

Security and reliability release, from a full review of the application ([docs/security.md](docs/security.md)).

### Added
- **Disk guard.** Recording pauses when free space (disk or hosting quota) drops below `MIN_FREE_GB` (default 5 GB) and continues by itself when there is room again. An alert warns at twice that value. A long or non-stop stream can no longer fill the hosting account, which would also break your website and email.
- **Length cap.** `MAX_RECORDING_HOURS` (default 0 = off) stops recording a stream after that many hours.
- **Log rotation.** A log larger than `LOG_MAX_MB` (default 5) becomes `recorder.log.1` (one old copy kept); the dashboard links the *older part*.
- **Log out and Log out everywhere** on the dashboard. The login cookie now carries its issue time and a session generation: the server rejects it after `COOKIE_DAYS`, and *Log out everywhere* ends every login at once.
- **Delete the plain-text key copy** from the dashboard once you've saved the key; only its hash remains.
- **Warning when the recorder folder is inside the website folder**, from the recorder (alert) and the dashboard (red notice). `.htaccess` only protects on Apache/LiteSpeed.
- **Word-level subtitle timing.** The PC scan uses speech models with word timing, so each subtitle appears when it's spoken. Lines break at pauses, sentence ends or about two lines of text. The plain models are the fallback.
- **`SECURITY.md`** with how to report a problem privately.
- **Secrets check** in the GitHub workflow: fails if a key, token or personal `config.php` is ever committed.

### Changed
- **The PC scan's speech library is pinned.**
  - **Where it comes from:** your server downloads it once from jsDelivr, records each file's checksum and serves this frozen copy.
  - **Checksums:** the page declares them in an import map, so the browser refuses a file that differs.
  - **Result:** the scan page loads no code from other sites, and jsDelivr is gone from its security policy.
  - **Its own key:** the scan page no longer receives the dashboard's action token. It gets a key that only works for that one recording's scan steps, for 24 hours.
- **The YouTube description is public-safe:** title, categories (with times), date, length and moments only. It used to include the internal summary with the server folder path, which shows the hosting username. This applies to the server and the PC uploader.
- **Links in YouTube moments** (from chat messages) become "[link]". `MOMENTS_QUOTE_CHAT = false` shows only the writer's name.
- **Only the app's own scripts run:**
  - **Nonce:** every page gets a random nonce per load instead of allowing any inline script.
  - **New headers:** `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`.
  - **Error display:** PHP errors are never shown on a page.
- **Server downloads** use https only, and requests carrying the Twitch or Google token never follow redirects.
- **Text from Twitch is cleaned on arrival.** Control characters and invisible text-direction characters are removed from titles, categories, names and chat. Alert colours are set by the recorder, never by words in a title.
- **Checked IDs:** the stream number in folder names (digits only) and YouTube's video ID.
- **Browser cache:** chat, subtitles and texts are never stored in it; video only briefly.
- **`record.php` refuses web requests before writing anything.**
- **`HOST=0.0.0.0 ./start-local.sh`** warns that the dashboard is then plain HTTP on your network and asks first.
- **GitHub workflow:** read-only token; actions pinned to exact commits.

### Fixed
- **Chat translation translated almost nothing.**
  - **Cause:** it relied on Chrome's language detector to recognise each message. Twitch chat is short slang with emotes, which it rarely recognises, so 1.4.0 still translated only a handful of messages per stream.
  - **Fix:** every message is now translated from the streamer's language unless the detector is sure it's another one.
  - **Scan log:** shows how many messages took each path, and any translator errors.
- **Overlong subtitles.**
  - **The problem:** speech recognition stretched lines over music, up to 9 minutes for "[Musik] [Musik] …".
  - **Fix:** sound tags are removed, and each line is on screen as long as it takes to read (1.5–7 s) where the speech is. In two real recordings, 222 lines longer than 12 seconds went to none.
  - **Older scans:** **Scan again** rebuilds their subtitles without redoing the speech recognition.
- **Phone layout.** Long text without spaces (a link in a title) widened the whole dashboard; it now wraps.

## [1.4.0] - 2026-11-25

### Added
- **Readable text version of a scan: `transcript.txt`.** Everything the streamer said and the whole chat, in order, with times in the video and the translation (→) under each line. It's linked as *text version* on the recording's line on the dashboard. The stand-alone scan writes it into the folder; *Download to this PC, then play* takes it along.

### Fixed
- **Chat translation translated hardly anything.** Chat messages are short, so Chrome's language detection is rarely sure about them, and the scan skipped every message it wasn't at least 70% sure of. Now, when the stream's own language is among the likely ones, the message is translated from that language. The scan log also says how many messages were skipped. Scan a recording again to redo its chat; the speech part is reused, so only the chat is translated again.
- **Scan page stretched wider than the window** when speech recognition produced one very long "word" (a glitch on noise or music). Long text now wraps.
- **Speech-recognition glitches are cleaned up:** the same word or phrase repeated over and over is cut to two times plus "…", runs of one repeated character are dropped, and very long lines are cut.

### Changed
- **The player's *Translated* button** shows how many chat messages have a translation, and says so when there are none.
- **The PC player's file picker** also accepts `.vtt` subtitle files.

## [1.3.3] - 2026-11-24

### Changed
- **The scan page says when it's waiting for the speech model.** It used to keep showing "downloading sound" while the model was still loading onto the graphics card. If loading takes over 4 minutes, it explains why and offers *Switch to "Good" and restart* (for *Best*) or *Reload and restart*. It also notes in the log when *Best* needs its full-size 2.7 GB version because the graphics card has no 16-bit support in the browser.

## [1.3.2] - 2026-11-24

### Changed
- **Progress while the server prepares the sound for a PC scan.** The scan page shows how far it is, the time left and the speed cap (e.g. *0:56:00 of 2:40:00 (35%) · ~25 min left · reads at most 4× real time*); the dashboard shows the percentage too. Before, it only said "preparing" for as long as it took.
- **Clearer speech-model status:** after the download it says the model is being loaded onto the graphics card, which takes a while for the large model.
- **Best quality** now states its real download size (1.5–3 GB).
- **"Scan on your PC?" card:** tidier layout with padding, the streamer above the title, a highlighted *Scan on this PC* button, and the YouTube-wait note in colour.

## [1.3.1] - 2026-11-24

### Added
- **Stand-alone PC scan: `pc-tools/scan.html`.** The same scan page, opened from disk: choose a recording folder on your PC, and it reads the sound straight from the MP4 file in the browser (AAC or Opus; normal and fragmented MP4) and writes subtitles, translations and translated chat into that folder for the PC player. For older footage that's not on the server. Resumable per 10-minute piece, like the dashboard version.

### Changed
- **The sound for a PC scan is also prepared while a stream records**, at `CONVERT_SPEED` (half the normal speed) to leave room for the recording. With `CONVERT_SPEED` 0 it still waits until nothing records.
- **The scan page says why it's waiting** (a stream is recording, a conversion is running, or the next cron run), and shows the speech model's download and status on a separate line.

## [1.3.0] - 2026-11-24

### Added
- **PC scan: subtitles, translation and spoken-word moments.** Speech recognition (Whisper) and translation run in Chrome/Edge on your own PC, on its graphics card; the server only prepares the sound ([docs/pc-scan.md](docs/pc-scan.md)).
  - **Asked after each stream:** with the streamer setting *PC scan after each stream: Ask me*, the dashboard asks *Scan on your PC?* when a stream ends. *Skip* uploads as before. Any recording can also be scanned later with its *Scan* button.
  - **Light on the host:** the server cuts only the sound track into 10-minute pieces (about 2 MB each), at twice `CONVERT_SPEED`, while nothing records.
  - **Resumable:** the scan page saves after every piece and continues where it stopped.
  - **Player:** subtitles menu (spoken language or translated) and a *Translated* switch for the chat. *Download to this PC, then play* includes these files.
  - **YouTube:** new moments rule *Streamer says*. A waiting upload holds until the scan is done or skipped (at most `SCAN_WAIT_HOURS`, default 24, without progress), so its title and description can include spoken-word moments.
- **Manual YouTube upload.** A *YouTube* button next to each finished recording uploads it to a chosen account, for streams recorded while the automatic upload was off. Same queue, title, description and moments as the automatic upload; waits for the MP4 conversion when one is pending. *Cancel upload* takes a queued upload out again; *Upload again* for recordings already on YouTube.

### Changed
- A queued upload that hasn't started yet shows as *upload queued* instead of *uploading 0%*.

## [1.2.0] - 2026-11-24

### Added
- **YouTube moments.** Rules on the dashboard scan a recording for specific moments:
  - **Rule types:** a chat message containing a word, a message from a user, a chat burst (at least N messages, optionally with a word, within S seconds), or the category/title containing a word.
  - **When it runs:** right after the stream and again just before the YouTube upload, so new rules also apply to recordings waiting for upload.
  - **What it changes:** matching labels are added to the YouTube title (`Title | Label`, shortened to fit 100 characters). The description gets a *Moments* list with timestamps, which YouTube makes clickable.
  - **Test:** *Test on this recording* shows the resulting title and description first.
  - **Where it's used:** the server upload and the PC uploader. Rules live in `moments.json`, the scanner in `server/moments.php`.
- **Full log per streamer.** *Full log* (without the routine offline lines), *everything* and *download* links on each streamer card. Behind the login like everything else.
- **Download speed cap.** Downloading a recording to your PC in the player is capped at `DOWNLOAD_LIMIT_MB` (default: 80% of `IO_LIMIT_MB`). It uses small evenly-paced pieces, so the server reads at a steady rate instead of in bursts that hit the host's IO limit.
- **Title and category changes are kept** (`meta.json` → `changes`, and in the end-of-stream summary), and can trigger YouTube moments.

### Fixed
- **Empty stream title.** When Twitch reported an empty title/category right at the start of a stream, the recording kept that empty title (and the alert showed a blank title). Every run now takes the latest title and category, logs changes, and alerts fall back to "(no title)".
- **Your Twitch name in the player** was only known after the next hourly token check; it's now fetched within minutes when missing.
- The player's download no longer waits for each group of pieces before starting the next (a steady pipeline instead).

## [1.1.0] - 2026-11-24

### Added
- **Player: chat search.**
  - **Search:** a search bar above the chat (docked and in the pop-out window) finds every message from a user across the whole recording, or every message containing a word (switch *User* / *Message*). Usernames autocomplete.
  - **Results:** each hit shows its timestamp, and clicking it jumps the video there. Matches are marked in the text, and every hit is shown as a tick on the seek bar.
  - **Shortcuts:** *Me* shows your own messages. Escape clears the search.
- **Player: preview pictures on the seek bar.** Hovering the seek bar shows a frame from that moment plus the time, like on YouTube. The pictures are made in the browser from the video file itself (nothing extra on the server) and remembered, so hovering back is instant. Available for recordings opened from your PC or downloaded through the dashboard. When streaming from the server only the time is shown, so no extra video is downloaded from your host.
- **Player: new controls.** Play/pause, ±10 s, mute and volume, playback speed (0.5×-2×), fullscreen, and a seek bar that grows on hover. The controls hide while playing. New keys: K (play/pause), M (mute). Double-click the video for fullscreen.
- **Player: your name highlighted in chat.** Your own messages get a purple marker, and messages mentioning you (with or without @) get a yellow one. When the player is opened from the dashboard, your name comes from the Twitch token. You can also set it by hand with ⚙ in the chat bar (remembered per browser), which also works in the stand-alone PC player.
- **Ad-free check explains itself.** At the start of a stream the log now records:
  - which account Twitch saw;
  - what Twitch reported (`subscriber`, `turbo`, `show_ads`);
  - the result of a second, direct check whether your account is subscribed to the channel (including tier and Prime).

  This tells apart "not subscribed", "subscribed but Twitch still serves ads", and "Twitch didn't recognise the login".
- **Ad-free re-check during a stream.** A "gets ads" decision is checked again every 30 minutes. If you subscribe mid-stream, the recording switches to your account for the rest of the stream, without restarting the video. A re-check that changes nothing isn't logged (unless `DEBUG`).
- The streamer card's *Watching as* shows the reason when your account isn't used: not subscribed, subscribed but Twitch still shows ads, or login not recognised.
- The token check stores your Twitch login name (used for the chat highlight).
- `CHANGELOG.md`.

### Changed
- The player uses its own controls instead of the browser's built-in ones (needed for the previews and search markers).
- Fullscreen now includes the controls.

## [1.0.0] - 2026-11-22

First public release.

- **Recorder:**
  - relay recording that survives hosts stopping long processes;
  - chat over WebSocket;
  - lossless MP4 conversion in the background (fragmented MP4, IO-friendly);
  - optional ad-free recording with your own Twitch token (encrypted);
  - optional resumable YouTube upload;
  - one cron line manages all streamers.
- **Dashboard:**
  - add, switch on/off and remove streamers;
  - alerts instead of email;
  - disk IO graph and server processes;
  - player with chat sync and download-to-PC;
  - deleting recordings;
  - login form, forced HTTPS and Cloudflare-aware brute-force protection.
- **Local mode:** `start-local.sh` for Linux, macOS and WSL.
- **PC tools:** a stand-alone player and a YouTube folder uploader.
- **Documentation** for web servers and local installs, configuration, security and troubleshooting.

[1.7.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.6.1...v1.7.0
[1.6.1]: https://github.com/LandonGam3r/stream-recorder/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.5.1...v1.6.0
[1.5.1]: https://github.com/LandonGam3r/stream-recorder/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.3...v1.4.0
[1.3.3]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.2...v1.3.3
[1.3.2]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/LandonGam3r/stream-recorder/releases/tag/v1.0.0

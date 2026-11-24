# Changelog

All notable changes to Stream Recorder™. Dates are release dates; versions follow [semantic versioning](https://semver.org/).

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

[1.3.2]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/LandonGam3r/stream-recorder/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/LandonGam3r/stream-recorder/releases/tag/v1.0.0

# Changelog

All notable changes to Stream Recorder™. Dates are release dates; versions follow [semantic versioning](https://semver.org/).

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

[1.2.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/LandonGam3r/stream-recorder/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/LandonGam3r/stream-recorder/releases/tag/v1.0.0

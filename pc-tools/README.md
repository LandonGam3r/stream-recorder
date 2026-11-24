# PC tools

Three optional tools for your own computer. All of them also work on their own, without the recorder.

## Stream player (`player.html`)
Plays a recording with its chat in sync - chat in a separate pop-up window you can put on a second screen, or docked next to the video.

1. You only need `video.mp4` and `chat.txt` from a recording. The chat file's first line (`#META ...`) holds the sync info. For recordings made before this was added, also take `meta.json` the first time and click **Save chat with sync info** in the player; that saves a `chat.txt` with the sync info built in, so `meta.json` isn't needed after that.
2. Double-click `player.html` (opens in your browser; the dashboard has the same player built in) → **Choose folder** → pick the recording folder. Or drag the files onto the page.
3. **Pop out chat** opens the chat window. If the browser blocks it, allow pop-ups for the page and click again. Closing the pop-up puts chat back next to the video.

Extras:
- Hover the seek bar for a preview picture of that moment.
- Search the chat by username (or switch to *Message* for the text). Click a result to jump there; hits also show as ticks on the seek bar.
- Set your Twitch name with ⚙ in the chat bar: your messages and mentions of you are highlighted, and **Me** lists your messages.
- Click a chat timestamp to jump to that moment.
- **Chat sync −/+** nudges chat if it feels early or late (remembered per recording).
- A−/A+ in the pop-up changes the text size.
- Keys: Space/K = play/pause, ←/→ = 5 s, M = mute, F = fullscreen (or double-click), C = pop out/dock chat.
- After a PC scan: a subtitles menu next to the speed control, and a **Translated** button in the chat bar.

Nothing is uploaded anywhere - the files are only read by your browser. `.ts` files can't play in browsers; recordings converted by the server are `.mp4`.

## PC scan (`scan.html`): subtitles and translation
Makes subtitles of what the streamer says, translated subtitles and a translated chat, for any MP4 on your PC, such as older recordings or footage from elsewhere. It's the same page the dashboard uses, but it reads the sound straight from the video file, so nothing goes through your server. More in [docs/pc-scan.md](../docs/pc-scan.md).

1. Double-click `scan.html` in Chrome or Edge (desktop, version 138+) → **Choose folder** → pick a folder with one recording: an `.mp4`, `.m4v` or `.mov`, and optionally `chat.txt`.
2. Choose the language spoken, what to translate to, and the quality → **Start scan**. The first time, the speech model downloads (about 300 MB on *Good*); the browser keeps it.
3. The results are written into that folder: `subtitles.vtt`, `subtitles.<language>.vtt`, `chat_translation.<language>.json` and `transcript.json`. Open the folder in `player.html` to watch with them.

- **Pausing is safe:** it saves after every 10 minutes of sound. Close the tab, choose the same folder later, and it continues.
- **Formats:** AAC and Opus sound in normal and fragmented MP4 (what the recorder makes). `.ts` and `.mkv` don't work: convert to MP4 first (`ffmpeg -i video.ts -c copy video.mp4`).
- **Spoken-word moments:** those are for YouTube uploads from the server. The stand-alone scan doesn't use the moments rules.

## YouTube uploader (`yt_uploader.py`)

Windows (uses Windows' own encryption for the stored sign-ins). Python 3.8+, no extra packages.
Uploads all videos in a folder (including subfolders) to a YouTube account. Large files are fine:
- An interrupted upload continues where it stopped the next time you run it.
- Files already uploaded are skipped.
- Recordings from the recorder get their title and description from `meta.json` / `summary.txt`; other files use the file name.

### One-time setup
**1. Python:** install from https://www.python.org/downloads/ - tick **"Add python.exe to PATH"**. No extra packages are needed.

**2. Google project** (makes your own "app" that may upload to your channel):
1. Go to https://console.cloud.google.com/ → create a project (e.g. "Stream uploader").
2. **APIs & Services → Library** → search **YouTube Data API v3** → **Enable**.
3. **Google Auth Platform / OAuth consent screen** → Get started: app name "Stream uploader", your email, audience **External**.
4. **Branding**: fill in *App name*, *User support email*, *Developer contact email*, and (required for publishing) *Application home page* and *Application privacy policy link*. Use a domain you own: e.g. `https://example.com` and `https://example.com/privacy`, with `example.com` as authorized domain. (Google only checks that these are filled in and belong to your domain; a simple privacy page saying the app is for your own uploads is enough.) Leave the logo empty (a logo makes Google demand a full review). **Save**.
5. **Audience** → click **Publish app** (status "In production"). Important: in "Testing" status Google ends the sign-in after 7 days and uploads stop. You don't need to submit it for verification for your own use; you'll just see an "unverified app" warning at sign-in.
6. **Clients** (or Credentials) → **Create client** → type **Desktop app** → create → **Download JSON**.
7. Rename the downloaded file to `client_secret.json` and put it in this folder (next to `yt_uploader.py`). Keep it private.

**3. Sign in:** double-click **YouTube Uploader.bat** → option **1** → give the account a short name (e.g. `main`). Your browser opens: choose the Google account **and the channel** the videos should go to. At the warning "Google hasn't verified this app", click **Advanced → Go to Stream uploader** (it's your own app). Repeat for more accounts/channels.

Account sign-ins are stored in `accounts\` and encrypted with Windows (they only work for your Windows user).

### Using it
Double-click **YouTube Uploader.bat**:
- **2. Upload a folder** - pick the folder, pick the account, and it uploads everything not uploaded yet.
- **3. Upload and keep watching** - same, then checks the folder every 5 minutes for new videos (leave the window open).
- **4. Export an account for the recorder** - creates `youtube_setup_<name>.json` for the recorder (see [docs/youtube.md](../docs/youtube.md)). Delete it from your PC after copying it to the recorder.

Settings are in `config.json` (created on first run):
- `privacy`: private / unlisted / public.
- `after_upload`: `keep`, or `move` to put uploaded files in an `_uploaded` subfolder.
- `category_id` and `chunk_mb`.

Upload results (YouTube links) are kept in `uploads.json`.

### Good to know
- **Videos are private.** Google forces videos from self-made upload tools to private until the Google project passes their compliance audit, and such videos can't be made public later. Fine as a private archive; for public videos you'd apply for the audit (in the Google Cloud console) first.
- **Verify the YouTube channel first** (https://www.youtube.com/verify, phone number): unverified channels can't upload videos longer than 15 minutes and get a very low daily upload limit.
- **Two different daily limits:** the *channel* limit (error `uploadLimitExceeded`, set by YouTube per channel, higher for verified/older channels, 24 hours) and the *Google project* quota (error `quotaExceeded`, about 100 uploads per day, resets at midnight Pacific time). Either way the tool stops and continues where it left off next time.
- **Files still being written** (changed in the last 2 minutes) are skipped until the next run.

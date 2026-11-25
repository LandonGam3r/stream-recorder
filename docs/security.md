# Security

To report a problem, see [SECURITY.md](../SECURITY.md).

## What protects what

**Dashboard login**
- **The key:** 192 random bits, created on the first run. It's compared as a SHA-256 hash with constant-time comparison. Guessing it isn't feasible.
- **The login form:** a POST form, so the key never appears in addresses, server logs or browser history.
- **Wrong keys:** after 10 wrong keys per hour (`MAX_FAILS`), that visitor is blocked for the hour.
- **Behind Cloudflare:** the visitor's real address is used. Cloudflare's `CF-Connecting-IP` header is only trusted when the request really comes from one of Cloudflare's published address ranges (built in; checked 2026-11-25, see *Things to keep up to date*). It can't be faked to get around the block or to lock you out.
- **The login cookie:**
  - **Contents:** its issue time and a session generation, signed with a key derived from the dashboard key's hash. It never contains the key.
  - **Expiry:** the server rejects it after `COOKIE_DAYS` (180).
  - **Flags:** HttpOnly, Secure and SameSite=Strict, limited to the dashboard's folder.
- **Log out:** **Log out** ends the login in this browser. **Log out everywhere** raises the session generation, which ends every login on every device at once.
- **HTTPS:** forced for real domain names, with HSTS (`FORCE_HTTPS`). Not forced on localhost or your home network.
- **Without a valid cookie:** the dashboard shows only the login form; every other address answers 404.

**Dashboard actions**
- **What can change things:** adding, switching or removing streamers and their settings; YouTube moments rules; queueing or cancelling an upload; PC-scan steps; deleting recordings or the plain-text key copy; logging out.
- **Protection against other sites:** every change needs a per-site token sent in a header, is checked against the request's origin, and relies on the SameSite cookie.
- **The scan page:** it doesn't get that token. It gets its own key, which only works for the scan steps of that one recording and expires after 24 hours.
- **Path tricks:** streamer and folder names are checked against a strict pattern and resolved to real paths inside the recordings folder; only a fixed list of file types is served.
- **Shell commands:** anything passed to them is escaped. No title, chat message or name ever reaches one.
- **Text from outside:**
  - **Display:** stream titles, categories, names, chat, speech-recognition text and translations are only ever inserted as plain text, never as page code.
  - **Cleaning:** control characters and the invisible characters that reverse text direction are removed when the text arrives from Twitch, so they can't fake log lines or flip what you see.
  - **Alert colours:** set by the recorder, never by words in a stream title.
- **Browser hardening:**
  - **Scripts:** a strict Content-Security-Policy. Only the page's own scripts run: each page load gets a random nonce that its scripts carry. No outside scripts, styles, images or frames.
  - **Headers:** `Permissions-Policy` (camera, microphone, location and similar off), `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy`, plus `X-Frame-Options: DENY`, `nosniff`, `no-referrer` and `noindex`.
  - **Browser cache:** chat, subtitles and texts are never stored in it; video only briefly.
  - **Errors:** PHP error messages are never shown on a page; they go to the server's log.

**PC scan (speech library)**
- **Where the library comes from:** the first time, your server downloads it from jsDelivr, records a SHA-256 checksum of every file and serves this frozen copy itself.
  - The page declares those checksums in an import map, so Chrome refuses a file that differs even by one byte.
  - A later change on jsDelivr can never reach your dashboard. The scan page loads no code from any other site.
- **Speech models:** they come from Hugging Face. They are data (weights and settings), not code.
- **Your data:** the sound and text stay between your PC and your server.
- **Stand-alone (`pc-tools/scan.html` from disk):** loads the library from jsDelivr directly. There's no dashboard or server key involved in that mode.

**Downloads by the server**
- **https only:** never other kinds of addresses (such as `file://`).
- **No redirects with tokens:** requests that carry your Twitch or Google token never follow a redirect, so a token can't be passed to another site.

**YouTube**
- **Public description:** title, categories, date, length and moments only. The internal summary (server folder, cron runs, gaps) stays in the recording folder.
- **Chat in moments:** links and e-mail addresses in quoted chat messages become "[link]". With `MOMENTS_QUOTE_CHAT = false` only the writer's name is shown.
- **Permission:** the Google sign-in only allows uploading (`youtube.upload`).

**Disk**
- **Free space:** recording pauses when free space drops below `MIN_FREE_GB` and continues by itself. An alert warns at twice that value.
- **Length cap:** `MAX_RECORDING_HOURS` can cap one recording.
- **Logs:** rotate at `LOG_MAX_MB`. A long stream can't fill the hosting account, which would also break your website and email.

**Stored tokens**
- **Encryption:** the Twitch token and YouTube sign-ins are stored with AES-256-GCM. The key is derived from `REC_KEY` with PBKDF2 (200,000 rounds).
- **Where `REC_KEY` lives:** only in the cron line or the start script's environment, never in a file next to the tokens. It is passed to child processes through the environment, never on a command line.
- **Setup files:** they're encrypted and then deleted (overwritten first).
- **The dashboard never shows tokens.** The Twitch token is your full browser login, not a limited key, so treat it like a password (see *Revoking*).

**Recorder folder**
- **On a web server:** it belongs outside the web root.
  - As a second line of defence, `recordings/`, `secret/` and `speech-lib/` deny web access via `.htaccess`. That only works on Apache and LiteSpeed.
  - The recorder alerts, and the dashboard shows a red warning, when the folder sits inside the website's folder.
- **From a browser:** `record.php` refuses to run when called that way, before it writes anything.

## What you should keep private

| What | Why |
|---|---|
| `REC_KEY` (cron line) | Unlocks the stored Twitch and YouTube tokens. |
| `secret/dashboard_key.txt` | The dashboard key. After your first login the dashboard offers a button to delete this plain copy; only its hash remains. |
| `secret/dashboard_key.hash` | A valid login cookie can be computed from it. |
| `pc-tools/client_secret.json`, exported `youtube_setup_*.json` | Upload access to your YouTube channel. |

## Things to be aware of

- **Same server account as your website.** On shared hosting your website's PHP code runs as the same user as the recorder. A security hole in any script on your site would give access to everything the recorder has, including the files and cron line above. Keep your website's software and libraries up to date, or give the recorder its own hosting account.
- **Same address as your website.** If the dashboard lives at `your-site/<folder>/`, a script-injection flaw elsewhere on that site could act on the dashboard while you're logged in. A separate subdomain (e.g. `rec.your-site.com`) isolates it completely.
- **The folder name isn't the protection.** Someone who finds it sees a login form; the key is what protects it.
- **Floods.** The dashboard answers wrong keys immediately (no worker is held up) and keeps its attempt list small. Large floods are a job for your host or a proxy like Cloudflare. Don't let Cloudflare cache the dashboard folder ("Cache Everything" rules).
- **Local network mode.** `HOST=0.0.0.0 ./start-local.sh` serves the dashboard over plain HTTP on your network; the script warns and asks first.

## Things to keep up to date

- **ffmpeg and PHP on the host:** the server copies video without decoding the picture, but keeping ffmpeg current covers the rest.
- **Cloudflare's address list:** it's built into `dashboard/index.php` and rarely changes. Compare it with https://www.cloudflare.com/ips/ once in a while.

## Revoking

- **Dashboard logins:** **Log out everywhere** on the dashboard. To change the key itself, delete `secret/dashboard_key.txt` and `secret/dashboard_key.hash`; the next cron run creates a new key.
- **Twitch token:** on twitch.tv → Settings → Security and Privacy, log out of all sessions. Then put a new token in `secret/token_setup.txt`.
- **YouTube:** myaccount.google.com → Security → Third-party access → remove your upload app.

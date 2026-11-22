# Security

## What protects what

**Dashboard login**
- **The key:** 192 random bits, created on the first run. It's compared as a SHA-256 hash with constant-time comparison. Guessing it isn't feasible.
- **The login form:** a POST form, so the key never appears in addresses, server logs or browser history.
- **Wrong keys:** after 10 wrong keys per hour (`MAX_FAILS`), that visitor is blocked for the hour.
- **Behind Cloudflare:** the visitor's real address is used. Cloudflare's `CF-Connecting-IP` header is only trusted when the request really comes from one of Cloudflare's published address ranges, so it can't be faked to get around the block or to lock you out.
- **The login cookie:** an HMAC derived from the key's hash. It never contains the key, and it is HttpOnly, Secure and SameSite=Strict. It is limited to the dashboard's folder and lasts 180 days.
- **HTTPS:** forced for real domain names, with HSTS (`FORCE_HTTPS`). Not forced on localhost or your home network.
- **Without the cookie:** the dashboard shows only the login form; every other address answers 404.

**Dashboard actions**
- **What can change things:** only adding, switching or removing streamers, their settings, and deleting recordings.
- **Protection against other sites:** every change needs a per-site token sent in a header, is checked against the request's origin, and relies on the SameSite cookie.
- **Path tricks:** streamer and folder names are checked against a strict pattern and resolved to real paths inside the recordings folder.
- **Shell commands:** anything passed to them is escaped.
- **Text from outside:** stream titles, chat and names are only ever inserted as plain text, never as HTML.
- **Browser hardening:** a strict Content-Security-Policy (no external scripts, styles, images or frames), `X-Frame-Options: DENY`, `nosniff`, `no-referrer`, `noindex`.

**Stored tokens**
- **Encryption:** the Twitch token and YouTube sign-ins are stored with AES-256-GCM. The key is derived from `REC_KEY` with PBKDF2 (200,000 rounds).
- **Where `REC_KEY` lives:** only in the cron line or the start script's environment, never in a file next to the tokens. It is passed to child processes through the environment, never on a command line.
- **Setup files:** they're encrypted and then deleted (overwritten first).
- **The dashboard never shows tokens.**

**Recorder folder**
- **On a web server:** it belongs outside the web root. As a second line of defence, `recordings/` and `secret/` deny web access via `.htaccess`.
- **From a browser:** `record.php` refuses to run when called that way.

## What you should keep private

| What | Why |
|---|---|
| `REC_KEY` (cron line) | Unlocks the stored Twitch and YouTube tokens. |
| `secret/dashboard_key.txt` | The dashboard key. Delete it once your browsers are logged in. |
| `secret/dashboard_key.hash` | A valid login cookie can be computed from it. |
| `pc-tools/client_secret.json`, exported `youtube_setup_*.json` | Upload access to your YouTube channel. |

## Things to be aware of

- **Same server account as your website.** On shared hosting your website's PHP code runs as the same user as the recorder. A security hole in any script on your site would give access to everything the recorder has, including the files and cron line above. Keep your website's software and libraries up to date.
- **Same address as your website.** If the dashboard lives at `your-site/<folder>/`, a script-injection flaw elsewhere on that site could act on the dashboard while you're logged in. A separate subdomain (e.g. `rec.your-site.com`) isolates it completely.
- **The folder name isn't the protection.** Someone who finds it sees a login form; the key is what protects it.
- **Floods.** The dashboard answers wrong keys immediately (no worker is held up) and keeps its attempt list small. Large floods are a job for your host or a proxy like Cloudflare: its free plan includes basic DDoS protection and one rate-limiting rule, which you could point at the dashboard path. A proxy only protects traffic that goes through it.
- **Revoking.** Cookies are revoked all at once: delete `dashboard_key.txt` and `dashboard_key.hash`.

Found a security problem? Please open an issue without exploit details, or contact the repository owner privately.

# Twitch token (optional): ad-free recording

Without a token, the recorder watches like a logged-out viewer.

**What changes without a token:** during ad breaks Twitch sends that viewer ad segments instead of the stream. The recorder throws those away, so the recording has a jump the length of the ad break (often 1–3 minutes).

**What the token adds:** on channels where your own account is ad-free (you're subscribed, or have Turbo), Twitch sends the real stream during ad breaks. With your token, those recordings have no gaps.

## Setting it up

1. Open twitch.tv in a **private/incognito window** and log in.
2. F12 → *Application* (Chrome/Edge) or *Storage* (Firefox) → *Cookies* → `https://www.twitch.tv` → copy the value of `auth-token`.
3. **Close the window without logging out.** The token stays valid until that session is logged out, or you change your password / log out everywhere.
4. Create `secret/token_setup.txt` in the recorder folder and paste the token into it.

The next run encrypts it into `secret/token.enc` with your `REC_KEY` and deletes the setup file. Without `REC_KEY` in the cron line (or start script) the token can't be stored or read, not even by the recorder itself.

## Per streamer

The dashboard's *Your Twitch login* setting:
- **Auto** (default): at the start of each stream, the recorder reads from Twitch's playback answer whether your account is ad-free on that channel. There's no extra request for this. Ad-free: it uses your token. Not ad-free: that stream is recorded anonymously, so your account isn't involved where it doesn't help.
- **Always**: always use the token.
- **Never**: never use it for this streamer.

## Health checks

- **When it's checked:** the token is checked once an hour (`TOKEN_CHECK_INTERVAL`), and immediately when Twitch rejects it at the start of a stream.
- **What you see:** the header shows its status. Alerts tell you when it stops working (and daily while it's broken), when it expires soon, and when it works again.
- **While it's broken:** recording continues anonymously.

**How visible this is to Twitch:**
- about 24 hourly checks per day;
- plus one playback request when a stream starts (and again only when that playback session expires, ~20 min, or the stream ends).

Runs reuse the playback session, so to Twitch it looks like one viewer who keeps watching.

## Keep in mind

- The token gives access to your Twitch account. It's stored encrypted, but treat `REC_KEY` like a password.
- Twitch's terms don't explicitly allow using your login in a tool like this; using it is your decision.

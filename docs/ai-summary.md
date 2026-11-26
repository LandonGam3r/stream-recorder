# AI summary of a stream

A written overview of a stream, so you know what happened without watching it all. It appears on the dashboard at the recording: **Summary** opens it. Every time in it (`0:18:48`) opens the player at that moment, so you can check.

There are two ways to make one:

| | Claude (default) | Chrome's built-in AI (free) |
|---|---|---|
| Where | Your server sends the material to Anthropic's API | In the browser on your PC (scan page) |
| Needs | An API key with a little prepaid credit | Chrome or Edge 138+, a strong PC |
| Reads | Speech (from the PC scan), chat, categories, moments **and pictures** | Speech and chat |
| Cost | Well under 1 cent per stream with Claude Haiku 5.5 | Free |
| Result | Headline, overview, timeline, highlights, chat, announcements | Headline, overview, timeline per 10 minutes |
| Automatic | Yes, after each stream (can be switched off) | No: you click *Summarize* |

Summaries are always written in English.

## Claude

### Set up (once)

1. Go to [platform.claude.com](https://platform.claude.com) (the Claude Console) and sign in or create an account. This is separate from a claude.ai subscription.
2. Under **Settings → Billing**, buy a little credit; $5 covers hundreds of summaries with Haiku. Max and Team plans include monthly API credits that you can claim there. You can also set a monthly spend limit.
3. Under **Settings → API keys**, click **Create key**, name it "Stream Recorder" and copy it.
4. On the dashboard, open the **AI summaries** card, paste the key and click **Save key**.

Within a minute the recorder encrypts the key into `secret/anthropic.enc`, the same way as the Twitch token: `REC_KEY` must be in your cron line or start script. It then deletes the plain copy and checks the key with Anthropic. The card shows the result (*key …abcd works*). The dashboard never shows the key again; **Remove key** deletes it.

### Settings (on the card)

- **Summarize each stream automatically** (on): after a stream ends, and after the MP4 conversion, Claude writes the summary. If the PC scan is set to *Ask me*, it waits for your choice (at most `SCAN_WAIT_HOURS`), so it can use what the streamer said. With *Skip* it's made from the chat, categories and pictures.
- **Include pictures from the video** (on): a picture every few minutes, plus one shortly after each category change. At most 48, 768 pixels wide. They let Claude see what game or scene was on screen.
- **Model:**
  - **Claude Haiku 5.5** (default): cheapest.
  - **Claude Sonnet 5.5**: writes better, and costs about 20 times as much (still around 10–20 cents per stream).

Any recording can also be summarised by hand: **Summarize** at the recording, or **Summarize again** under an existing summary.

### How it's made

1. **Waits** until the stream has ended, the MP4 conversion is done and the PC-scan choice is made.
2. **Pictures:** a short ffmpeg job takes them, seeking to each point, so only a few MB of the video are read, not the whole file.
3. **Sends** the material as one Anthropic *Message Batch*: a job that's processed within minutes (at most a day) at half price. The server doesn't have to wait for it, which suits hosts that end long processes.
4. **Collects** the result: every 2 minutes the recorder checks; when it's ready, it saves `ai_summary.md`, deletes the pictures and shows an alert with the cost.

The material:
- **Stream details:** title, start, length, category over time, and moments found by your rules.
- **Pictures**, each with its time in the video.
- **What the streamer said:** from the [PC scan](pc-scan.md)'s `transcript.json`, with times.
- **Chat:** with a time mark per minute. Links become `[link]`.

A 3–4 hour stream is about 60,000–80,000 tokens. Very long streams are trimmed to fit: every 2nd (4th, …) chat message is used, then fewer pictures.

### Cost

The cost of each summary is shown under it, and the card shows the total. Prices used for the estimate (batch, USD per million tokens, November 2026):

| Model | Input | Output |
|---|---|---|
| Claude Haiku 5.5 | $0.05 (prompts up to 100,000 tokens), $0.25 above | $0.25, $1.25 above |
| Claude Sonnet 5.5 | $1 | $5 |

The real charge is on your Console's usage page.

### Privacy

The material above goes to Anthropic. Under Anthropic's [commercial terms](https://www.anthropic.com/legal/commercial-terms) for the API, Anthropic may not train models on it. The chat is public on Twitch anyway; the summary itself stays on your server.

## Chrome's built-in AI (no API key)

With no API key set, **Summarize** at a recording opens the scan page in summary mode; click **Write summary**. You can also tick *also write a summary* on the scan page, so it's made right after a scan. The stand-alone `pc-tools/scan.html` offers the same tick box and writes `ai_summary.md` into the folder.

- **Needs:** Chrome or Edge 138 or newer on Windows, macOS or Linux, a graphics card with more than 4 GB memory (or 16 GB RAM and 4 CPU cores), and about 22 GB free disk space. The first time, Chrome downloads its model (Gemini Nano; Phi in Edge), once.
- **How:** the model only reads a few pages at a time, so the stream is summarised per 10 minutes, then those notes become the overview and the headline.
- **Languages:** it reads English, German, Spanish, French and Japanese. For other languages it uses the English translation from the PC scan.
- **No pictures**, and less detail than Claude. Nothing leaves your PC.

## Files

In the recording folder:
- `ai_summary.md`: the summary (Markdown).
- `ai_summary.json`: state, model, cost and source.
- `ai_frames/`: the pictures, only while a summary is being made.

In the recorder folder:
- `summary_settings.json`: the card's settings.
- `secret/anthropic.enc`: the encrypted key.
- `secret/anthropic_status.json`: the key check.

## When it goes wrong

The recording's line shows the state:
- *waiting*, with the reason;
- *taking pictures from the video*;
- *Claude is writing it*;
- *failed*, with the reason and **try again**.

| Message | What to do |
|---|---|
| Anthropic rejected the API key | Copy the whole key again, or create a new one. |
| waits for an API key | The key was removed, or `REC_KEY` is missing from the cron line (the system log says so). |
| Claude returned an error: … credit … | Add credit under Billing in the Console. |
| Anthropic did not get to it within a day | Rare (very busy); use **Summarize again**. |

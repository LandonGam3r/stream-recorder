# PC scan: subtitles, translation and spoken-word moments

A web host can't run speech recognition, but your PC can, in the browser on its graphics card. The PC scan uses that to make, per recording:

- **subtitles** in the language the streamer speaks;
- **translated subtitles**, for example from Dutch to English;
- **translated chat** (optional), shown in the player with the *Translated* switch;
- **a readable text version** (`transcript.txt`): what the streamer said and the chat, in order, with times and translations;
- **spoken-word moments** for YouTube: the rule type *Streamer says* finds words the streamer said, like *Chat message contains* does for chat ([dashboard.md → YouTube moments](dashboard.md#youtube-moments)).

## Without the server: `pc-tools/scan/scan.html`

For older footage, or recordings you only have on your PC: open `pc-tools/scan/scan.html` from disk in Chrome or Edge, choose the recording folder, and start. It reads the sound straight from the MP4 (AAC or Opus; normal or fragmented) and writes the subtitle and translation files into that folder, where `player.html` finds them. See [pc-tools/README.md](../pc-tools/README.md#pc-scan-scanscanhtml-subtitles-and-translation).

## How the subtitles are timed

- **Word timing:** the scan uses speech models that give the time of every word ("_timestamped" versions). Each subtitle line then appears when it's spoken, and a new line starts at a pause, at the end of a sentence, or after about two lines of text.
- **Fallback:** if those models can't load on your PC, the plain models are used with one time per sentence. Each line is then kept on screen only as long as it takes to read (1.5–7 seconds), placed where the speech is.
- **Sound tags:** "[Musik]", "*Music*" and similar are removed.
- **Older scans:** **Scan again** rebuilds their subtitles with these rules without redoing the speech recognition.

## The speech library

- **Opened from the dashboard:** the first scan asks your server to download the speech library once (about 25 MB, at the next cron run). The server records a checksum of every file and serves this frozen copy, so the page loads no code from other sites. See [security.md](security.md).
- **Stand-alone version:** loads the library from jsDelivr directly.

## AI summary

When no Claude API key is set on the dashboard, the scan page offers *also write a summary with Chrome's built-in AI*: after the scan, it writes an [AI summary](ai-summary.md) on your PC (free, nothing is sent). The stand-alone version saves it as `ai_summary.md` in the folder. With a key, Claude makes the summaries: automatically after each stream (the AI summaries card), or with **Summarize** at a recording.

## What you need

- **Chrome or Edge on a desktop PC**, version 138 or newer.
- **A graphics card** for reasonable speed. The browser uses it through WebGPU. Without one, speech recognition runs on the processor and takes several times longer than the stream itself.
- **For translation:** the translator built into Chrome/Edge. Google lists 16 GB of memory and 4 processor cores, or a graphics card with more than 4 GB of memory. Language packs download the first time each language pair is used.

## How it works

1. **After a stream**, for streamers whose setting *PC scan after each stream* is *Ask me*, the dashboard shows **Scan on your PC?** at the top. *Skip* uploads to YouTube as before, with moments from chat only. You can also scan any recording later with its **Scan** button.
2. **Scan on this PC** opens the scan page in a new tab, and the server prepares the sound at its next cron run:
   - ffmpeg cuts only the sound track into 10-minute pieces (mono, 16 kHz, about 1.5–2.5 MB each).
   - This reads the recording once, at twice `CONVERT_SPEED`, and not while a conversion runs.
   - **During a recording** it runs at `CONVERT_SPEED` instead, to leave room for the recording; with `CONVERT_SPEED` at 0 it waits until nothing records. The scan page shows why it's waiting.
3. **On the scan page:**
   - **Choose:** the language the streamer speaks, what to translate to, whether to translate the chat, and the speech quality.
   - **Press Start scan.** The first time, the browser downloads the speech model from Hugging Face; after that it is kept by the browser.

   | Quality | Model | Download |
   |---|---|---|
   | Fast | Whisper base | ~150 MB |
   | Good (recommended) | Whisper small | ~300 MB |
   | Best | Whisper large-v3 turbo | 1.5–3 GB, needs a strong graphics card (8 GB+); "Good" is usually the better balance |

4. **Each piece:** the page downloads the sound, recognises the speech, translates it and saves the text on the server right away.
   - **Pausing is safe:** you can close the tab and continue later; it picks up from the last saved piece.
   - **A new translation only translates:** scanning again with the same language and quality but another target language reuses the earlier speech recognition.
5. **When it's done:**
   - The server writes `subtitles.vtt` (spoken language) and `subtitles.<language>.vtt` (translation), deletes the sound pieces, and adds spoken-word moments at the next cron run.
   - A waiting YouTube upload then starts, with those moments in its title and description.
   - Translated chat is saved last (`chat_translation.<language>.json`), so it never holds up the upload.

## How long the YouTube upload waits

While a scan is asked for or running, that recording's upload waits. It goes ahead without the scan when:
- you press *Skip*;
- you cancel the scan on the dashboard;
- you choose *Give up* on the scan page;
- nothing happens for `SCAN_WAIT_HOURS` (default 24): no answer, or no progress.

## In the player

- **Subtitles:** a subtitles menu appears next to the speed control when a recording has them: *Spoken (Dutch)* or *English (translated)*. Your choice is remembered.
- **Translated chat:** the *Translated* button in the chat bar shows messages in your language. Hover a message to see the original.
- **Downloaded copies:** *Download to this PC, then play* also saves the subtitle and chat translation files, so they work offline and in the PC player too.

## Limits

- **Speech recognition makes mistakes.** It struggles with music, game sound, shouting, slang and several people talking at once. Moments from speech are only as good as the recognition.
- **No language detection for speech:** pick the language the streamer speaks; it's remembered per streamer.
- **Chat language detection:** chat is short slang with emotes, which language detection rarely recognises. So every message is translated from the streamer's language, unless the detector is sure it's another language. Messages that come back unchanged (already in your language, or only names and emotes) are left out. The scan log shows how many messages took each path.
- **Chat language packs:** chat in other languages is translated when Chrome already has that language pack. The scan page lists the rest and offers a button to download those packs and translate them too.
- **Privacy:**
  - **The page loads two things from outside:** the speech library from jsDelivr and the model from Hugging Face. The scan page's security policy allows only those, and only on that page.
  - **Nothing about your streams goes there:** the sound and text stay between your PC and your server.

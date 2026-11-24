# YouTube upload (optional)

Finished (converted) streams can be uploaded to a YouTube channel automatically, per streamer. Uploads are resumable and go in pieces, so any file size works and interruptions just continue later.

## Setting it up

You need your own small "app" at Google once. It's free, and it's what allows uploading to your channel.

1. **Google project and sign-in:** follow *One-time setup* in [pc-tools/README.md](../pc-tools/README.md). There you create the Google project and sign in your channel with the PC uploader (Windows, Python, no extra packages), e.g. as account `main`.
2. **Export:** in the uploader's menu choose **Export an account for the recorder**. This creates `youtube_setup_main.json`.
3. **Hand it to the recorder:** put that file in the `secret/` folder next to `record.php`. The next run encrypts it with your `REC_KEY` and deletes the file. **Delete it from your PC too**: it grants upload access to the channel.
4. **Choose it per streamer:** on the dashboard, pick `main` under *Upload to YouTube* for each streamer whose streams should go there.

After a stream ends and is converted, the recorder uploads it in the background (`UPLOAD_BUDGET` seconds per run, only while nothing records by default). Alerts show the link when it's done, or any problem (expired sign-in, daily limit); it retries by itself. The recording stays where it is.

The PC uploader also works on its own, to upload a folder of videos from your PC.

## Moments in title and description

Optional rules on the dashboard tag uploads with what happened in the stream (a raid, a hype moment, a category), with timestamps in the description. See [dashboard.md → YouTube moments](dashboard.md#youtube-moments).

## Limits to know

- **Videos are private.** Google forces videos from self-made upload apps to private until the app passes Google's compliance audit, and they can't be made public later. Fine as a private archive. For public videos you'd apply for the audit first.
- **Verify the channel** (https://www.youtube.com/verify): unverified channels can't upload videos longer than 15 minutes and have a very low daily limit.
- **Two daily limits:**
  - **Channel limit** (error `uploadLimitExceeded`): set by YouTube per channel, often around 10–15 uploads a day for newer channels.
  - **Google project quota** (error `quotaExceeded`): about 100 uploads a day.
  - Either way, uploading pauses and continues later.
- **Publish the Google app** (status "In production"). In "Testing" status Google ends the sign-in after 7 days and uploads stop.

Only upload streams you have the rights to, or the streamer's permission for.

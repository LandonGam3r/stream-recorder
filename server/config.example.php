<?php
/**
 * Stream Recorder™ - recorder settings.
 *
 * Copy this file to config.php (same folder as record.php) and change what you need.
 * Every line is optional: anything you leave out (or comment out) uses the default shown here.
 * config.php is never overwritten when you update record.php.
 *
 * The passphrase that encrypts your tokens (REC_KEY) is NOT set here on purpose: it goes in
 * the cron line / start script, so the key never sits next to the files it protects.
 */

// ---------------------------------------------------------------- recording

// 'best' (source quality), or a rendition such as '1080p60', '720p60', '720p30', '480p30',
// '360p30', '160p30', 'audio_only'. Falls back to 'best' if a channel doesn't offer it.
// Rough sizes per hour: best/1080p60 ≈ 2.8 GB, 720p60 ≈ 1.6 GB, 480p30 ≈ 0.6 GB.
define('QUALITY', 'best');

// Also record the chat (chat.txt, synced to the video in the player).
define('RECORD_CHAT', true);

// Time zone used in logs, folder names and chat times, e.g. 'Europe/Amsterdam', 'America/New_York'.
// '' = the server's own setting.
define('TIMEZONE', '');

// Relay: a recording run hands over to the next run (started a minute later) once it has recorded
// MIN_RUNTIME seconds and the next one is ready, so no video is lost between runs. MAX_RUNTIME is a
// safety cap in case no next run shows up. MAX_RUNTIME = 0: one run records until the stream ends
// (only if your host lets a process live that long).
define('MIN_RUNTIME', 50);
define('MAX_RUNTIME', 300);

// Seconds between video playlist refreshes while recording.
define('POLL_INTERVAL', 2);

// While a switched-on streamer is offline, each run keeps checking every N seconds (for ~50 s) so a
// recording starts within ~N seconds of going live. 0 = check once a minute (less server load).
define('OFFLINE_CHECK_INTERVAL', 10);

// ---------------------------------------------------------------- Twitch token (optional)

// Seconds between checks that your Twitch token still works (3600 = hourly). See docs/twitch-token.md.
define('TOKEN_CHECK_INTERVAL', 3600);

// ---------------------------------------------------------------- MP4 conversion

// Convert each finished recording to MP4 (lossless, needs ffmpeg).
define('AUTO_CONVERT', true);

// Path to ffmpeg. '' = auto-detect: the system's ffmpeg, or a static build placed at bin/ffmpeg
// next to record.php.
define('FFMPEG_PATH', '');

// Delete the original .ts parts after a successful, length-checked conversion.
define('DELETE_TS_AFTER_CONVERT', true);

// Only start conversions (and YouTube uploads) while nothing is recording. Recommended on shared
// hosting with a disk IO limit.
define('CONVERT_WHEN_IDLE', true);

// Speed cap as a multiple of real time (0 = as fast as possible). On shared hosting with a ~7 MB/s
// IO limit, 4 keeps a 1080p60 conversion just under the limit.
define('CONVERT_SPEED', 0);

// How often a conversion that crashes or is stopped by the host is retried.
define('CONVERT_ATTEMPTS', 3);

// Fragmented MP4: flat memory use (~50 MB) however long the stream. A normal MP4 needs memory that
// grows with the length (150+ MB for 3.5 h at 60 fps), which hosting memory limits can kill.
define('CONVERT_FRAGMENTED', true);

// ---------------------------------------------------------------- YouTube upload (optional)

// Privacy of uploaded videos: 'private', 'unlisted' or 'public'. Google forces 'private' for upload
// apps that haven't passed their audit, which a personal Google project hasn't. See docs/youtube.md.
define('YOUTUBE_PRIVACY', 'private');

// YouTube category id (20 = Gaming).
define('YOUTUBE_CATEGORY', '20');

// Seconds of uploading per cron run (the upload continues in the next run), and piece size.
define('UPLOAD_BUDGET', 45);
define('UPLOAD_CHUNK_MB', 16);

// ---------------------------------------------------------------- logging

// true = log every run, including routine "offline" checks and handovers (useful while setting up).
define('DEBUG', false);

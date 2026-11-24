<?php
/**
 * Stream Recorder™ - dashboard settings.
 *
 * Copy this file to config.php (same folder as index.php) and change what you need.
 * Every line is optional: anything you leave out uses the default shown here.
 */

// The recorder folder (the one with record.php in it). '' = find it automatically. It looks in:
//   - the RECORDER_DIR environment variable (start-local.sh sets it),
//   - ../server                       (the layout of this repository),
//   - two levels up: twitch-recorder, stream-recorder or stream-recorder/server
//     (e.g. /home/you/public_html/<dashboard>  ->  /home/you/twitch-recorder).
define('RECORDER_DIR', '');

// How long a browser stays logged in, in days.
define('COOKIE_DAYS', 180);

// Your host's disk IO limit in MB/s (cPanel: Resource Usage). Drawn as a dashed line in the IO
// graph; 0 = no line.
define('IO_LIMIT_MB', 0);

// Speed cap in MB/s when the player downloads a recording to your PC, so the download stays under
// your host's disk IO limit instead of hitting it in bursts. 'auto' = 80% of IO_LIMIT_MB (no cap when
// IO_LIMIT_MB is 0), 0 = no cap, or a number like 5.
define('DOWNLOAD_LIMIT_MB', 'auto');

// Wrong keys per visitor per hour before that visitor is blocked for the rest of the hour.
define('MAX_FAILS', 10);

// Redirect http:// to https:// and tell browsers to always use HTTPS for this site (HSTS).
// 'auto' = yes for real domain names, no for localhost, IP addresses and local names (.local, .lan),
// so it works on your own computer or home network without a certificate. true / false = always / never.
define('FORCE_HTTPS', 'auto');

// Time zone for times shown on the dashboard, e.g. 'Europe/Amsterdam'. '' = the recorder's.
define('TIMEZONE', '');

// Your own page icon: a png, jpg, gif, webp or ico file (max 300 KB), as a full path or relative to
// this folder (e.g. 'icon.png'). '' = built-in icon.
define('ICON_FILE', '');

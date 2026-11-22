# Install on your own computer

Runs on Linux, macOS, or Windows 10/11 through WSL (Windows Subsystem for Linux). Good for a home server, a NAS with a shell, a Raspberry Pi, or just trying it out.

## Requirements

| | Debian/Ubuntu/WSL | macOS (Homebrew) |
|---|---|---|
| PHP 8.0+ with curl | `sudo apt install php-cli php-curl` | `brew install php` |
| ffmpeg (for MP4, optional) | `sudo apt install ffmpeg` | `brew install ffmpeg` |

## Start

```bash
git clone https://github.com/LandonGam3r/stream-recorder.git
cd stream-recorder
./start-local.sh
```

The script:
1. checks PHP, its extensions and ffmpeg;
2. asks for a passphrase (`REC_KEY`) that encrypts the optional Twitch/YouTube tokens. Press Enter to skip it if you don't use those; if you do, use the same passphrase every time;
3. starts the dashboard at `http://127.0.0.1:8080/` with PHP's built-in web server;
4. runs the recorder every minute, like cron would;
5. prints the login key (also saved in `server/secret/dashboard_key.txt`).

Open the address, log in, add streamers. Stop with **Ctrl+C**. Recording runs that already started finish by themselves within about 5 minutes, and anything left open is wrapped up the next time you start.

Recordings are saved in `server/recordings/<streamer>/`. To keep them on another drive, move that folder and put a symbolic link in its place: `ln -s /mnt/big-disk/recordings server/recordings`.

## Options

| Variable | Default | |
|---|---|---|
| `PORT` | `8080` | Dashboard port |
| `HOST` | `127.0.0.1` | `0.0.0.0` makes the dashboard reachable from other devices on your network |
| `PHP` | `php` | Path to a specific PHP binary |
| `REC_KEY` | (asked) | Set it to skip the question, e.g. for autostart |

```bash
HOST=0.0.0.0 PORT=9000 ./start-local.sh
```

On your own computer or network the dashboard doesn't force HTTPS (`FORCE_HTTPS = 'auto'`), so plain `http://` works without a certificate. If you make it reachable from the internet, put it behind a web server with HTTPS instead (see [install-webserver.md](install-webserver.md)).

## Running it permanently

**Option A: cron** (no start script needed for recording):

```
* * * * * REC_KEY=YourLongPassphrase123 php -q /path/to/stream-recorder/server/record.php >> /path/to/stream-recorder/server/cron_output.txt 2>&1
```

Then serve the `dashboard/` folder with any web server with PHP, or with `php -S 127.0.0.1:8080 -t dashboard`.

**Option B: systemd service** (Linux) running the start script:

```ini
# /etc/systemd/system/stream-recorder.service
[Unit]
Description=Stream Recorder
After=network-online.target

[Service]
User=you
WorkingDirectory=/path/to/stream-recorder
Environment=REC_KEY=YourLongPassphrase123
ExecStart=/path/to/stream-recorder/start-local.sh
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now stream-recorder
```

## Notes

- The **IO graph** needs Linux (`/proc`). On macOS it stays empty; everything else works.
- **Windows without WSL** isn't supported: the recorder relies on Unix process tools.
- The PC [player](../pc-tools/README.md) opens recordings straight from `server/recordings/` too.

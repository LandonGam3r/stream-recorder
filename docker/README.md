# Stream Recorder™ in Docker

Optional: the normal way is a web host with a cron job (see [install-webserver.md](../docs/install-webserver.md))
or `start-local.sh` on your own computer. Docker runs the same program in one container: the recorder every
minute and the dashboard on port 8080, with ffmpeg, GD and a font for thumbnails included.

## Start

From the repository folder:

```sh
cp docker/example.env docker/.env      # then fill in REC_KEY and TZ
docker compose -f docker/compose.yaml up -d --build
docker compose -f docker/compose.yaml logs   # shows your dashboard key
```

Open http://localhost:8080 and log in with that key.

- **REC_KEY** encrypts your Twitch, YouTube and Claude keys: 16 or more letters and digits. Keep it safe and
  never change it, or the stored keys can't be read.
- **TZ** is your time zone (for example `Europe/Amsterdam`), so times in chat and recordings match your clock.

## Where things are

Everything you keep is in the `stream-recorder-data` volume, mounted at `/data`:

| In the container | What |
|---|---|
| `/data/server/recordings/` | the recordings |
| `/data/server/secret/` | the dashboard key and your encrypted tokens |
| `/data/server/channels.json` | the streamer list (edit it on the dashboard) |
| `/data/server/config.php` | optional settings: copy `config.example.php` next to it and change what you need |
| `/data/dashboard-config.php` | optional dashboard settings (its `config.example.php`) |

The program files in `/data/server` are replaced from the image at every start; your `config.php`, keys and
recordings are never touched. The image itself contains only the program (`Dockerfile.dockerignore`).

## Update

```sh
git pull
docker compose -f docker/compose.yaml up -d --build
```

## Reaching it from other devices

The compose file publishes port 8080 on this computer only (`127.0.0.1`). To open the dashboard from your phone
or from the internet, put it behind a reverse proxy that adds HTTPS (Caddy, nginx, Traefik) and forward to
port 8080. Over plain HTTP your key and recordings would travel unencrypted.

## Using the recordings on your PC

`docker cp stream-recorder:/data/server/recordings ./recordings` copies them out, or use the dashboard's player
(**Download to this PC, then play**). The PC tools in `pc-tools/` work with those folders as usual.

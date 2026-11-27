# Stream Recorder™ in Docker: the recorder (every minute, like cron) and the dashboard in one container.
# Build and start from the repository folder:  docker compose -f docker/compose.yaml up -d --build
# Everything you keep (settings, keys, recordings) lives in the /data volume; see docker/README.md.
FROM php:8.3-cli-bookworm

# ffmpeg (MP4, clips, thumbnails, gap repair), GD with FreeType and a font (thumbnail text), tini (stops cleanly).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core tini \
        libfreetype6 libfreetype6-dev libjpeg62-turbo libjpeg62-turbo-dev libpng16-16 libpng-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apt-get purge -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev; \
    apt-get autoremove -y; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'exit(function_exists("imagettftext") && extension_loaded("curl") && extension_loaded("mbstring") ? 0 : 1);'

# Only the program: Dockerfile.dockerignore keeps your own config.php, keys and recordings out of the image.
COPY server/ /opt/stream-recorder/server/
COPY dashboard/ /opt/stream-recorder/dashboard/
COPY docker/entrypoint.sh /usr/local/bin/stream-recorder
RUN chmod 755 /usr/local/bin/stream-recorder && useradd --system --uid 1000 --home /data recorder \
    && mkdir -p /data && chown recorder /data /opt/stream-recorder/dashboard

USER recorder
VOLUME /data
EXPOSE 8080
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/stream-recorder"]

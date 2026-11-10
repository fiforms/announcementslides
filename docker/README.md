# Running AnnouncementSlides with Docker

The image bundles nginx, php-fpm, the queue worker (thumbnails etc.) and the
scheduler, plus ffmpeg, so one container is the whole web app. It is built by
[.github/workflows/docker.yml](../.github/workflows/docker.yml) and published to
`ghcr.io/fiforms/announcementslides` (`linux/amd64` and `linux/arm64`).

## Quick start (app + MySQL)

```bash
cd docker
cp .env.example .env
docker run --rm ghcr.io/fiforms/announcementslides php artisan key:generate --show
# put that value in APP_KEY, set DB_PASSWORD and APP_URL in .env
docker compose up -d
```

Open <http://localhost:8080>. Migrations run automatically on start
(`AUTO_MIGRATE=false` disables that).

Create the first admin and add the widgets:

```bash
docker compose exec app php artisan user:create          # prompts for details
docker compose exec app php artisan widget:install resources/widgets/clock resources/widgets/calendar
```

Any `docker compose exec app <command>` / `docker compose run --rm app <command>`
runs as `www-data`.

## Single container with SQLite

```bash
docker run -d -p 8080:80 -v announcementslides:/var/www/html/storage \
  -e APP_KEY=base64:... -e APP_URL=http://localhost:8080 \
  ghcr.io/fiforms/announcementslides
```

The SQLite file lives in the storage volume, so that one volume is everything.

## Where state lives

| What | Where |
|---|---|
| Uploaded slides, thumbnails, widget bundles, SQLite file (if used) | volume mounted at `/var/www/html/storage` |
| MySQL data | the `db` volume |
| `APP_KEY` | your `.env` — **keep it**; a restore needs the same key |

Remote S3 storage: set the `AWS_*` variables and `FILESYSTEM_DISK` as described
in [config/filesystems.php](../config/filesystems.php) (add them to the `environment:` block).

## Behind a reverse proxy

Terminate TLS in the proxy, point it at port 8080, set `APP_URL` to the public
https URL, and set `TRUSTED_PROXIES` to the proxy's IP/CIDR.

## Building locally

```bash
git submodule update --init resources/widgets
docker build -t announcementslides .
```

## Updating

```bash
docker compose pull && docker compose up -d
```

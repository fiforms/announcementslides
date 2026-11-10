# Running AnnouncementSlides with Docker

The image bundles nginx, php-fpm, the queue worker (thumbnails etc.) and the
scheduler, plus ffmpeg, so one container is the whole web app. It is built by
[.github/workflows/docker.yml](../.github/workflows/docker.yml) and published to
`ghcr.io/fiforms/announcementslides` (`linux/amd64` and `linux/arm64`).

## Quick start

```bash
cd docker
docker compose up -d
```

That's it: no `.env` and no secrets are needed. The app runs on SQLite at
<http://localhost:8080>, migrations run on start (`AUTO_MIGRATE=false` disables
that), and an `APP_KEY` is generated on first start and kept in the storage volume.

To configure anything, copy `.env.example` to `.env` and uncomment what you
need. Every variable in `.env` is passed into the container as-is, so any Laravel
setting (mail, Google sign-in, S3 storage, `APP_URL`, ...) works without editing
`compose.yaml`.

Create the first admin and install the widgets:

```bash
docker compose exec app php artisan user:create          # prompts for details
docker compose exec app php artisan widget:install resources/widgets/clock resources/widgets/calendar
```

Any `docker compose exec app <command>` / `docker compose run --rm app <command>`
runs as `www-data`.

## MySQL instead of SQLite

```bash
docker compose -f compose.yaml -f compose.mysql.yaml up -d
```

Set `DB_PASSWORD` in `.env` for anything reachable from outside (the default is
only safe because MySQL isn't published on a host port). Use the same `-f` pair
for every later `docker compose` command.

## Single container without Compose

```bash
docker run -d -p 8080:80 -v announcementslides:/var/www/html/storage \
  -e APP_URL=http://localhost:8080 ghcr.io/fiforms/announcementslides
```

## Where state lives

| What | Where |
|---|---|
| Uploaded slides, thumbnails, widget bundles | volume at `/var/www/html/storage` |
| SQLite database (default) | same volume, `storage/database/database.sqlite` |
| Generated `APP_KEY` | same volume, `storage/app/.app_key` |
| MySQL data (overlay only) | the `db` volume |

With the defaults, the storage volume is the whole installation. It contains the
`APP_KEY`, so treat a backup of it as sensitive. If you set `APP_KEY` yourself,
keep a copy: a restore needs the same key to read encrypted values and sessions.

Remote S3 storage: set `FILESYSTEM_DISK` and the `AWS_*` variables in `.env`
(see [config/filesystems.php](../config/filesystems.php)).

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

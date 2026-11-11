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

That's it: no `.env` and no secrets are needed. The app runs with a bundled MySQL at
<http://localhost:8080>, migrations run on start (`AUTO_MIGRATE=false` disables
that), and an `APP_KEY` is generated on first start and kept in the storage volume.

To configure anything, copy `.env.example` to `.env` and uncomment what you
need. Every variable in `.env` is passed into the container as-is, so any Laravel
setting (mail, Google sign-in, S3 storage, `APP_URL`, ...) works without editing
`compose.yaml`.

Then open <http://localhost:8080>. A new install has no users yet, so it takes you
to a one-page **setup wizard** that creates the first admin and (by default) installs
the bundled clock, calendar and weather widgets. It signs you in when done. The wizard
exists only while there are no users: once an account exists `/setup` is a 404 and
cannot be reopened.

By default the wizard is **open**, so whoever reaches the page first creates the admin.
That is fine for a local test, but for anything reachable from outside set a
`SETUP_TOKEN` in `.env` (the form then requires it) or create the admin from the
command line before exposing the port:

```bash
docker compose exec app php artisan user:create          # prompts for details
docker compose exec app php artisan widget:install resources/widgets/clock resources/widgets/calendar
```

The wizard's admin has a password, so the compose file shows the email/password login
form (`PASSWORD_LOGIN=true`). Set it to `false` once Google sign-in is configured.

Any `docker compose exec app <command>` / `docker compose run --rm app <command>`
runs as `www-data`.

## Database

The image supports **MySQL only** (SQLite is for local development outside
Docker). `compose.yaml` starts MySQL 8.4 for you; its default password is only
reasonably safe because the port isn't published on the host, so set
`DB_PASSWORD` in `.env` for anything reachable from outside. To use your own
MySQL server, set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and
`DB_PASSWORD` in `.env` and start only the app: `docker compose up -d --no-deps app`.

## Single container without Compose

You need a MySQL server reachable from the container:

```bash
docker run -d -p 8080:80 -v announcementslides:/var/www/html/storage \
  -e APP_URL=http://localhost:8080 \
  -e DB_HOST=mysql.example.com -e DB_DATABASE=announcementslides \
  -e DB_USERNAME=announcementslides -e DB_PASSWORD=... \
  ghcr.io/fiforms/announcementslides
```

## Where state lives

| What | Where |
|---|---|
| Uploaded slides, thumbnails, widget bundles | volume at `/var/www/html/storage` |
| Generated `APP_KEY` | same volume, `storage/app/.app_key` |
| MySQL data | the `db` volume (or your own MySQL server) |

A complete backup is the MySQL database plus the storage volume. The volume
contains the generated `APP_KEY`, so treat a copy of it as sensitive. If you set `APP_KEY` yourself,
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

## Image tags

| Tag | What it is |
|---|---|
| `latest` | the newest published **release** (what `compose.yaml` uses) |
| `1.4.0`, `1.4` | an exact release, or the newest patch of a minor version; pin one with `APP_IMAGE` |
| `edge` | a manual build of unreleased work, for testing only |

Maintainers: see [RELEASING.md](RELEASING.md) for how each tag is published.

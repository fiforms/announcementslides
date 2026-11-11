# Running AnnouncementSlides with Docker

The image bundles nginx, php-fpm, the queue worker (thumbnails etc.) and the
scheduler, plus ffmpeg, so one container is the whole web app. It is built by
[.github/workflows/docker.yml](../.github/workflows/docker.yml) and published to
`ghcr.io/fiforms/announcementslides` (`linux/amd64` and `linux/arm64`).

## Quick start

`compose.yaml` is the only file you need. The main [README](../README.md#docker)
has the three-line version:

```bash
curl -O https://raw.githubusercontent.com/fiforms/announcementslides/master/docker/compose.yaml
# set APP_URL at the top of compose.yaml to the address people will type into a browser
docker compose up -d
```

No secrets are needed. The app runs with a bundled MySQL, migrations run on
start (`AUTO_MIGRATE=false` disables that), and an `APP_KEY` is generated on first
start and kept in the storage volume.

Settings live in the **YOUR SETTINGS** block at the top of `compose.yaml`: `APP_URL`
and the setup token are there, and mail, Google sign-in, S3 storage, the site name and
the rest are commented out ready to uncomment. Any Laravel setting can be added to that
block. (An optional `.env` file next to `compose.yaml` is also passed through if you
prefer to keep secrets out of the compose file; a value set in the settings block wins
over the same name in `.env`.)

Then open `APP_URL`. A new install has no users yet, so it takes you to a one-page
**setup wizard** that creates the first admin and (by default) installs the bundled
clock, calendar and weather widgets. It signs you in when done. The wizard exists only
while there are no users: once an account exists `/setup` is a 404 and cannot be reopened.

By default the wizard is **open**, so whoever reaches the page first creates the admin.
That is fine for a local test, but for anything reachable from outside uncomment
`SETUP_TOKEN` in the settings block (the form then requires it), or create the admin
from the command line before exposing the port:

```bash
docker compose exec app php artisan user:create          # prompts for details
docker compose exec app php artisan widget:install resources/widgets/clock resources/widgets/calendar
```

The wizard's admin has a password, so `PASSWORD_LOGIN` is `"true"` in the compose file,
which shows the email/password login form. Set it to `"false"` once Google sign-in is
configured and you want it Google-only.

Any `docker compose exec app <command>` / `docker compose run --rm app <command>`
runs as `www-data`.

## Database

The image supports **MySQL only** (SQLite is for local development outside
Docker). `compose.yaml` starts MySQL 8.4 for you; its default password is only
reasonably safe because the port isn't published on the host, so set
`DB_PASSWORD` (in the shell or a `.env` file next to `compose.yaml`) for anything
reachable from outside. To use your own MySQL server, set `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` the same way and start only the app:
`docker compose up -d --no-deps app`.

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

Remote S3 storage: set `FILESYSTEM_DISK` and the `AWS_*` variables in the settings block
(see [config/filesystems.php](../config/filesystems.php)).

## Behind a reverse proxy

Terminate TLS in the proxy, point it at port 8080, set `APP_URL` to the public
https URL, and set `TRUSTED_PROXIES` to the proxy's IP/CIDR (both in the settings block).

## Building locally

```bash
git submodule update --init resources/widgets
docker build -t announcementslides:local .
cd docker && APP_IMAGE=announcementslides:local docker compose up -d
```

(`APP_IMAGE` makes Compose use your local build instead of pulling the published image.)

## Updating

```bash
docker compose pull && docker compose up -d
```

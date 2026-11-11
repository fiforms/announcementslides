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

A complete backup is the MySQL database plus the storage volume (see [Backups](#backups)). The volume
contains the generated `APP_KEY`, so treat a copy of it as sensitive. If you set `APP_KEY` yourself,
keep a copy: a restore needs the same key to read encrypted values and sessions.

Remote S3 storage: set `FILESYSTEM_DISK` and the `AWS_*` variables in the settings block
(see [config/filesystems.php](../config/filesystems.php)).

## Backups

One mechanism covers everything: a ZIP with a `manifest.json` (app version, last
migration, database driver, timestamp, contents), a `mysqldump` of the database and,
for a **full** backup, the uploaded slide files and widget bundles. It is created by

| Trigger | How |
|---|---|
| Command line | `docker compose exec app php artisan backup:create [--full]` |
| Schedule | Database-only, daily at 02:30 UTC by default, keeps the newest 14 (`BACKUP_SCHEDULE=off\|daily\|weekly`, where weekly runs Sundays; `BACKUP_TIME` in UTC; `BACKUP_KEEP`) |
| Image update | Before `migrate` runs on start, if the install already exists and migrations are pending (`BACKUP_BEFORE_MIGRATE=false` disables; keeps the newest 3, `BACKUP_KEEP_SAFETY`). If that backup fails, the container does not start. |
| Web | **Admin → Backups**: database-only or full, with download and delete. |

Archives are written to `storage/app/private/backups/` (never web-served). That is the
same volume as your data, so **download copies and keep them elsewhere**
(`docker compose cp app:/var/www/html/storage/app/private/backups ./backups`). Backups
you start yourself (web or CLI) are never pruned automatically.

The archive does **not** contain the `APP_KEY`. Without it a restored site works, but
everyone has to sign in again and widget API keys (Admin → Widgets) must be re-entered;
nothing else is encrypted. Keep `APP_KEY` (or `storage/app/.app_key`) if you want a seamless move.
Full backups skip files on S3 storage (use the provider's versioning/replication).

### Restoring

Restoring is command-line only:

```bash
docker compose exec app php artisan backup:list
docker compose exec app php artisan backup:restore backup-20261111-023000-database-12.zip
# or a file you copied in:  docker compose cp my.zip app:/tmp/my.zip
docker compose exec app php artisan backup:restore /tmp/my.zip [--db-only|--files-only] [--force]
```

It checks the manifest, refuses a backup from a newer app version or another database
type, takes a database backup of the current state first (`--no-safety-backup` skips),
puts the site in maintenance mode, loads the database, runs any newer migrations, and
(full backups) copies the files back, overwriting files with the same name. It warns if the `APP_KEY` differs.

## Behind a reverse proxy

Terminate TLS in the proxy, point it at port 8080, set `APP_URL` to the public
https URL, and set `TRUSTED_PROXIES` to the proxy's IP/CIDR (both in the settings block).

## Building locally

```bash
npm run build:docker        # inits the widgets submodule, then docker build -t announcementslides:local .
cd docker && APP_IMAGE=announcementslides:local docker compose up -d
```

(`APP_IMAGE` makes Compose use your local build instead of pulling the published image.)

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

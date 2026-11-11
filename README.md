# AnnouncementSlides

A web-based announcement slide distribution system designed for Seventh-day Adventist churches. Administrators and authorized contributors can push slides to any church in the system; registered users can submit slides for review; and church leaders can build the shows their own congregation displays.

> **Docs:** [Docker deployment](docker/README.md) · [Manual / developer install](INSTALLING.md) · [Architecture](ARCHITECTURE.md) · [Slide Announcer devices](SLIDE_ANNOUNCER.md) · [Release notes](CHANGELOG.md)

## Features

- **Multi-tier role system** — site admins and contributors, plus per-church leaders, editors and followers, each with scoped permissions
- **Church directory integration** — congregations and pastors imported from the Adventist directory, organized by conference
- **Submission and review workflow** — users submit slides that are approved before distribution; slides can be scheduled and expire automatically
- **Shows** — each church assembles one or more shows, optionally with a background, overlay and live widgets (clock, calendar, weather) framing every slide
- **Overlay editor and widgets** — build text, QR-code and image overlays in the browser; install overlay widgets as packages
- **Shareable playback links** — a no-login link for each screen (a hallway TV, a lobby display)
- **Slide Announcer devices** — a Raspberry Pi appliance that pairs with a church and shows its slides full-screen, even through network drops
- **Downloads and exports** — ZIP, PowerPoint and REVELation Snapshot exports
- **Image quality tools** — validation, plus in-browser 2× AI upscaling and downscaling, with no server GPU required
- **Backups** — scheduled, on-demand and pre-update backups, with a command-line restore
- **Google sign-in** and **English / Spanish** interface, with slides tagged by language

## Quick start (Docker)

The recommended way to run AnnouncementSlides is the prebuilt Docker image (`amd64` and
`arm64`, published to GHCR). It runs the web app, queue worker and scheduler together,
and the Compose file adds MySQL, so you only need Docker.

```bash
curl -O https://raw.githubusercontent.com/fiforms/announcementslides/master/docker/compose.yaml
# set APP_URL at the top of compose.yaml to the address people will type into a browser
docker compose up -d
```

Then open `APP_URL` (default <http://localhost:8080>). A new install has no users, so
it opens a **setup wizard** that creates your administrator account and installs the
bundled widgets (clock, calendar, weather). To upgrade, run
`docker compose pull && docker compose up -d`.

**If the server is reachable from the internet, set `SETUP_TOKEN` in `compose.yaml`
before you start it.** Until the wizard has been completed, anyone who opens the page
first can become the administrator; the token makes the form ask for a code you
chose.

[docker/README.md](docker/README.md) covers HTTPS behind a reverse proxy, email, Google
sign-in, S3 storage, backups and restores, image tags, and building the image yourself.

## Other ways to install

Running without Docker — a local development checkout, or a production server with your own
nginx/PHP-FPM, queue worker and cron — is covered in [INSTALLING.md](INSTALLING.md). That
file also has the Google OAuth setup, church-data loading and the Artisan command reference.

## Development

Requires PHP 8.4+, Composer and Node.js. See [INSTALLING.md](INSTALLING.md#local-development-install)
for the full setup, then:

```bash
composer dev      # app server + queue worker + logs + Vite
composer test     # PHPUnit
```

[ARCHITECTURE.md](ARCHITECTURE.md) describes the project layout, data model and where to
start for common changes.

## License

MIT License (see LICENSE.md)

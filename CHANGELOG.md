# Changelog

## 0.2.0 — 2026-11-11 (initial release)

The first tagged release of AnnouncementSlides: a web app for distributing
announcement slides to Seventh-day Adventist churches, with a prebuilt Docker
image and an optional Raspberry Pi display appliance (Slide Announcer).

### Install

```bash
curl -O https://raw.githubusercontent.com/fiforms/announcementslides/master/docker/compose.yaml
# set APP_URL at the top of compose.yaml to the address people will type into a browser
docker compose up -d
```

Open `APP_URL`; a setup wizard creates your administrator account and installs the
bundled clock, calendar and weather widgets. The image is published for `amd64` and
`arm64` as `ghcr.io/fiforms/announcementslides:0.2.0` (also `:0.2` and `:latest`).
If the server is reachable from the internet, set `SETUP_TOKEN` before the first start.
See [docker/README.md](docker/README.md); a non-Docker install is described in
[INSTALLING.md](INSTALLING.md).

### Highlights

**Slides and workflow**
- Global slides published by admins and contributors; local slides managed by each church.
- Viewers can submit slides for review; admins approve or reject them.
- Scheduled publishing and automatic expiry, with an archive for expired slides.
- Slides are tagged by language (English, Spanish) and filtered by interface language.
- Admins can mark a global slide *immutable* so leaders cannot remove it from their Main Show.
- Optional nearby sharing of a church's slides with congregations within a set radius.
- Slides can carry multiple media (overlay, flyers, social image) plus a text description and link.
- Slides can be created without an image (overlay and widgets only).
- Short random public tokens, or a chosen link name, in slide URLs.

**Roles and people**
- Global roles (admin, contributor, viewer, banned) and per-church roles (leader, editor, follower).
- Church leaders get a **Team page** to add or remove editors; people invited by email are
  matched when they sign in with Google or verify their address.
- Google sign-in, invitations and an admin Users page.
- Per-church switch to allow or disallow subscriptions.

**Church directory**
- Import congregations and pastors from the Adventist directory (admin upload, preview and
  confirm, or the `church:*` commands); pastors become church leaders.
- Missing church coordinates can be filled in from the free U.S. Census geocoder.

**Shows, overlays and widgets**
- Each church builds shows from its slides; a show can have a **frame**: a background
  (image, video or YouTube), an overlay and live widgets drawn over every slide.
- Browser-based overlay editor: text, shapes, images, QR codes and imported SVG.
- Installable overlay widgets (zip packages); clock, calendar and weather ship with the app.
  Widget data is fetched through a server-side policy that allows only public HTTPS.
- Shareable no-login **play links** for unattended screens, revocable per link.
- Admin **Promo Shows** that can be hot-linked from the global view.

**Downloads and exports**
- ZIP and PowerPoint downloads (video slides export as still frames) and
  REVELation Snapshot (`.revel`) export.

**Image quality**
- Resolution, file-size and 16:9 checks with clear warnings; low-quality global uploads
  from non-admins are blocked.
- In-browser 2× AI upscaling, downscaling of oversized images and JPEG compression, with
  undo/redo — nothing is processed on the server.
- Chunked uploads for large videos; video thumbnails via ffmpeg.

**Slide Announcer devices**
- Raspberry Pi appliances pair with a church using a short code, sync that church's shows,
  and keep playing from a local cache when the network drops.
- Devices update over the air (OS image and local app, with automatic rollback), and
  admins publish and tag releases from the web UI.
- Includes Slide Announcer OS image platform 0.4.6, local app platform 0.5.3 and the
  Slide Announcer product 0.2.0. Device versions are tracked separately from the web app.

**Operations**
- Docker image bundling nginx, PHP-FPM, queue worker, scheduler and ffmpeg, with MySQL in
  the Compose file. Migrations run on start and an `APP_KEY` is generated automatically.
- **Backups**: database-only or full archives from the command line, a daily schedule,
  automatically before an update that runs migrations, and **Admin → Backups**.
  Restore with `backup:restore`.
- Interface available in English and Spanish, with a per-language site name.

### Security notes
- Uploaded SVGs are sanitized before they become web-reachable, and served with a
  restrictive Content-Security-Policy.
- The Docker image's nginx refuses to execute PHP from the uploads directory.
- Real client IPs are recorded behind a reverse proxy when `TRUSTED_PROXIES` is set.

### Known limitations
- Backups require MySQL; on SQLite the commands and the admin page say so.
- Restoring a backup is command-line only.
- Show frames are per church; there are no global or conference-wide frames yet.
- The AI upscaler runs on the user's GPU and refuses to run on a CPU-only browser.
- This is a 0.x release: expect changes between minor versions. Take a backup before
  upgrading (the Docker image does so automatically before running migrations).

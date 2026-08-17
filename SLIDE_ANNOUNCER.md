# Slide Announcer — Architecture Design (server + device)

## Context

AnnouncementSlides currently distributes slides only through a web UI that
humans view in a browser. The next extension, **Slide Announcer**, is a
physical device — a Raspberry Pi plugged into a church TV — that pairs with a
site (`Entity`) and displays that site's slide deck as digital signage,
continuing to run from local cache if the network drops. This requires new
capability on both sides: a device-facing API on this Laravel app (which
today has *no* API at all — Inertia only), and an entirely new device
repository (OS image, local kiosk app, update tooling). "Slide Announcer" is
used as the consistent name for the feature across both repos — the device
model/table/routes on the server side, and the device repo itself.

**Status (2026-08-09): Part 1's pairing + heartbeat + fleet-inventory core is
implemented** — Sanctum wiring, the `slide_announcers` /
`slide_announcer_pairing_codes` / `slide_announcer_heartbeats` migrations,
the pairing/heartbeat/sync API controllers, and the entity-leader pairing UI
(`Entity/SlideAnnouncers.vue`) all exist and are described below as built,
not planned. Two things called out in Part 1 as deliberately out of scope
for this pass:
- **`Admin/SlideAnnouncerReleaseController` + `Admin/SlideAnnouncerReleases.vue`
  now exist** (added 2026-08-10, after this bullet was first written as "not
  built") — chunked upload (release files can be multi-GB), a details
  lightbox with copy-to-clipboard/download, and channel tag/untag actions.
  `php artisan slide-announcer:publish-release` still works too, same
  underlying model either way. The cross-site `Admin/*` fleet view is still
  not built. Revisit once there's an actual fleet to look at.
- **Heartbeat retention is a manual command, not a query scope** —
  `php artisan slide-announcer:prune-heartbeats` deletes rows older than
  `slide_announcer.heartbeat_retention_days` (default 30). Run it from
  outside the app (cron/systemd timer on the server host) — this repo has
  no in-app scheduler today.

**Update 2026-08-10: releases are unified across OS and local-app.** What
was `slide_announcer_os_releases` (OS bundles only) is now
`slide_announcer_releases`, with a `kind` column (`os`|`app`) — the same
rollout mechanism now covers local-app archives too, not just RAUC
bundles. This replaced the app-update side's old two-value
`config('slide_announcer.app_version'/'app_download_url')` source of truth
entirely (see "Heartbeat + version checks," below, for the current
contract).

**Update 2026-08-10 (later the same day): channel became a tag, and
architecture was added.** A release's channel is no longer a column on
`slide_announcer_releases` (and there's no `is_active` flag either) — see
"New data model," below, for why: a build can now be current on more than
one channel at once (e.g. promoted to `testing` while still tagged
`developer`), and the same physical file/URL never changes regardless of
which channel(s) point at it. A device's own hardware architecture
(`arm64`, `armhf`, ...) is now part of matching, too, via a new
`SlideAnnouncer.architecture` column populated from the heartbeat body.
Both changes required emptying and recreating `slide_announcer_releases`
on the one production server that already had the pre-tagging shape —
confirmed OK since it held no real fleet data yet, only this development
cycle's own test rows.

Pairing (Part 2) is now implemented device-side too — see that section's
status note for exactly what's still a stub (slide sync, kiosk
rendering).

## Repo strategy

The device side lives in a separate repo, [`slideannouncer`](https://github.com/fiforms/slideannouncer),
added as a **git submodule** at [`slideannouncer/`](slideannouncer/) in this
repo. A submodule was chosen (over a fully independent, unlinked repo) so the
two version histories can be pinned together — a commit in this repo
references an exact commit of the device repo, which matters once the API
contract between them starts evolving and you want to know exactly which
device-repo state a given server release was built/tested against.

Tradeoff to keep in mind going forward: submodule commits need an explicit
`git submodule update` after every pull where the pointer moved, and CI here
would need `--recurse-submodules` if it ever needs the device repo's
content.

---

## Part 1 — Server-side additions (this repo)

### Auth model
`SlideAnnouncer` is its own Sanctum-tokenable model — not a `User` subtype,
and **paired to a site (`Entity`), never to an individual `User`** — an
entity can have multiple users (leaders/contributors) who all need equal
access to the same paired devices, so tying a device to one person's account
would be wrong. Sanctum's `personal_access_tokens` table is polymorphic, so
a device doesn't need to be shoehorned into `users`/`user_entities` (which
model *human* membership and admin/viewer roles) — it's a scoped API client
pinned to exactly one `Entity`.

Wiring needed:
- Publish Sanctum's migration (`personal_access_tokens` — polymorphic
  `tokenable_type`/`tokenable_id`, no schema changes needed).
- Add `api` guard to `config/auth.php`; register `routes/api.php` via
  `withRouting(api: ...)` in [bootstrap/app.php](bootstrap/app.php).
- Guard device routes with `auth:sanctum` + a thin
  `abort_unless($request->user() instanceof SlideAnnouncer, 403)` middleware
  (`slide-announcer.auth`) rather than ability-string gymnastics — cheapest
  thing that works given no User-issued API tokens exist yet.
- Skip `EnsureFrontendRequestsAreStateful` — devices use bearer tokens, not
  cookies, so no stateful-SPA wiring is needed.
- **Decided: no `HasApiTokens` on `User`, no user-level API tokens at all.**
  The device is the only thing this API authenticates — pairing, sync, and
  heartbeat are all device-to-server. There's no near-term need for a human
  to hold an API token (a future mobile app would be a separate decision
  made on its own merits, not hedged for here), so `User` stays exactly as
  it is today.

**Post-pairing credential: pre-shared bearer token (Sanctum PAT), not an
RSA keypair.** This is a deliberate choice, not a default fallen into:
- A Sanctum personal access token *is* the "automatically generated key"
  the pairing exchange needs — a long random secret, generated server-side
  at pair time, handed to the device once, stored hashed (SHA-256) in
  `personal_access_tokens` — so a database leak doesn't hand out usable
  tokens directly, which is the main risk an asymmetric scheme would
  otherwise be buying you protection against.
- An RSA/mutual-TLS scheme (device holds a private key, server verifies
  signed requests against a stored public key) is real extra work here:
  Sanctum has no built-in support for it, so it means a custom auth guard
  on the server, a signing library and keypair-generation/storage story on
  every device, and a more complex pairing exchange (device generates a
  keypair, sends the *public* half during pairing instead of just receiving
  a token). That complexity would mainly defend against a threat that
  matters more for device-to-device mesh trust than for a star topology
  where every device only ever talks to one server over TLS — bearer
  token + HTTPS already covers the realistic risk (interception).
- Revocation, rotation, and per-device scoping all work identically either
  way (a token row to delete vs. a public key to remove) — so RSA doesn't
  buy anything on that front either.
- RSA/X.509 signing *does* show up elsewhere in this design, where it's
  the native mechanism rather than extra work: RAUC bundle signing (Tier 1,
  below) is certificate-based by design, so that's the natural home for
  asymmetric crypto in this system rather than duplicating it for API auth.

### New data model
**`slide_announcers`**: `id, entity_id (FK, cascade), name, mac_address
(nullable), device_uuid (nullable, unique), app_version, os_version,
architecture (nullable, self-reported via heartbeat — see Part 2's
`heartbeat.py`), update_channel (enum: stable/testing/developer, default
stable), auto_update_enabled (bool, default true), settings (json,
nullable), last_seen_at, last_ip, last_cpu_temp_c, paired_at, paired_by
(FK users), revoked_at, timestamps`. Relationships: `Entity::slideAnnouncers()` hasMany,
`SlideAnnouncer::entity()` belongsTo, `SlideAnnouncer` uses
`Laravel\Sanctum\HasApiTokens`. This table *is* the fleet inventory — every
device is always attached to exactly one site, so "devices at this church"
and "all devices across every church" are both just queries against it (see
Admin/entity-leader visibility, below).

- `mac_address` and `device_uuid` are deliberately separate: `mac_address`
  is informational (hardware inventory — "which physical unit is this"),
  while `device_uuid` is the identifier the device itself generates and
  persists, and the pair sent at pairing time. See "Device identity &
  anti-clone protection" under Tier 2 for why both are needed and how the
  device keeps them consistent.
- `update_channel` + `auto_update_enabled` are per-device controls an entity
  leader or admin sets from the website — combined with the device's own
  self-reported `architecture`, they drive which `slide_announcer_releases`
  row (`kind = 'os'`) a given device is offered, and whether its own
  update-check unit is allowed to actually install it or just report
  availability (see Heartbeat, below).
- `settings` is a free-form JSON blob (display duration, transition style,
  and future per-device display options) — see "Presenter/display
  settings," below.

**`slide_announcer_pairing_codes`**: `id, code (unique, 6-digit numeric),
entity_id (FK), created_by (FK users), expires_at, used_at,
slide_announcer_id (nullable FK, set on consumption), timestamps`.

**`slide_announcer_releases`** (covers both RAUC OS bundles and local-app
archives — see Tier 1 and Tier 2): `id, kind (string: os|app), version
(string), architecture (string, e.g. arm64/armhf/x64), release_type
(string: full|hotfix|disk_image, added 2026-08-10 later still),
required_base_version (string, nullable), disk_path, sha256, notes,
created_by (FK users), timestamps`. A row is an **immutable uploaded
build** — one file, one URL, forever. There is deliberately no `channel`
or `is_active` column here (there was, briefly, on 2026-08-10, before this
section was rewritten the same day — see the status note above): which
channel(s) a build is current on is a fact about a *tag*, not the build,
because the same file can be current on more than one channel at once
(promoted to `testing` without losing its `developer` tag) — a single
`is_active` boolean per row can't represent that. `architecture` is a
plain string with no fixed list (unlike `kind`, which validates against
`SlideAnnouncerRelease::KINDS`) — a new architecture needs a new value,
not a code change; see Part 2's `heartbeat.py`, which reports
`platform.machine()` verbatim as this value.

**`release_type` covers four real artifact shapes across two `kind`
values**: `(os, full)` is a `.raucb` RAUC OTA bundle, `(os, hotfix)` is a
`.raucb` RAUC bundle that only applies cleanly on top of one specific
prior version (recorded in `required_base_version`), `(os, disk_image)`
is a flashable `.img.xz` disk image for re-imaging an SD card by hand
(never an OTA candidate — `SlideAnnouncerRelease::resolveForDevice()`
never returns one), and `(app, full)` is the local-app `.tar.gz` archive,
unchanged. `SlideAnnouncerRelease::parseFilename()` recognizes
`slideannouncer-X.X.X.raucb` (full) and
`slideannouncer-X.X.X.hotfix.from.Y.Y.Y.raucb` (hotfix) — for any of the
three extensions — to auto-fill the admin upload form's
version/type/base-version fields; `kind`/`release_type` stay explicit
admin selections regardless, since the filename alone doesn't say which
of the two `kind`s a given upload belongs to. `tagChannel()`'s per-slot
eviction (below)
and `resolveForDevice()`'s hotfix-exact-match resolution (used by the
heartbeat controller in place of a plain `currentOnChannel()->first()`)
are what let a full release and one or more hotfixes — each targeting a
different `required_base_version` — stay tagged on the same channel at
once.

**`slide_announcer_release_channels`** (the tag table): `id,
slide_announcer_release_id (FK, cascade), channel (string: stable/
testing/developer), tagged_by (FK users, nullable), created_at` (no
`updated_at` — a tag is created or deleted, never edited). Releases
occupy independent **slots** per `(kind, architecture, channel)`: one slot
for the full release, one for the disk image, and one per distinct
`required_base_version` among hotfixes — enforced in
`SlideAnnouncerRelease::tagChannel()` (deletes any other release
occupying that same slot, then creates this one; a "move," not a toggle),
not a DB constraint, the same portability tradeoff `NearbyEntities::within()`
and the old `is_active`-per-channel logic already made elsewhere in this
codebase. This is what lets a full OS release and two hotfixes (targeting
different base versions) all be tagged `stable` at once. `untagChannel()`
just deletes this release's own row for that channel — a channel can end
up pointing at nothing for some architecture, that's fine, no replacement
is forced.

**"Archived" is a derived state, not a column**: `SlideAnnouncerRelease::isArchived()`
is simply "this release has zero rows in `channels()`." Untagging a
release from every channel doesn't touch its file or its URL — it's still
fully downloadable, just not current anywhere. The admin UI
(`Admin/SlideAnnouncerReleases.vue`) renders this as two sections, Current
and Archived, computed client-side from the same `channels` array the API
already returns per release — no separate "archived" endpoint.

Tagging works identically for either kind — publish, then tag (at publish
time or later):
```
php artisan slide-announcer:publish-release os  <path.raucb>  <version> <architecture> [--channel=<name>]
php artisan slide-announcer:publish-release app <path.tar.gz> <version> <architecture> [--channel=<name>]
```
or via the admin GUI (`Admin/SlideAnnouncerReleases.vue` — upload form has
an optional "Tag as" select; the details lightbox lets you add/remove
tags on an existing release afterward). Files live on the same `Storage`
disk (`public` — S3/R2 in prod, local in dev) as everything else — slides,
thumbnails, and now release artifacts — under
`slide-announcer/releases/{kind}/{architecture}/{version}{original-extension}`
(e.g. `slide-announcer/releases/os/arm64/2026.08.1.raucb`,
`slide-announcer/releases/app/arm64/0.2.0.tar.gz`) — note **no channel
segment**, since the URL must never change just because a tag moved. Both
the CLI and the GUI compute `sha256` server-side and preserve whatever
extension the source file had (handling multi-dot extensions like
`.tar.gz` correctly, not just the last segment).

One releases table instead of two near-identical ones (`..._os_releases` +
`..._app_releases`) because the shape is identical and the only thing that
differs is which heartbeat field consumes the row — see
`SlideAnnouncerHeartbeatController` querying
`SlideAnnouncerRelease::currentOnChannel('os', $device->architecture, $device->update_channel)`
and the same for `'app'`.

**`slide_announcer_heartbeats`** (new — the rolling per-device log the
master `slide_announcers` row doesn't have room for): `id,
slide_announcer_id (FK, cascade), app_version, os_version, ip_address,
cpu_temp_c, created_at` (no `updated_at` — a log row is never edited).
`slide_announcers` itself keeps only the *latest* snapshot of these fields
(`last_ip`, `last_cpu_temp_c`, plus `app_version`/`os_version`) for the
fleet-list view; this table is the history behind it, one row per heartbeat
(default every 5 minutes, device-side — see Part 2's sync/heartbeat client).
Pruned by age (`slide_announcer.heartbeat_retention_days`, default 30) via
`php artisan slide-announcer:prune-heartbeats`, not a query scope — this is
an operational log, not user-facing content like `Slide`'s expiry scopes.

Revocation is `revoked_at` + deleting the device's Sanctum tokens, not a hard
delete — keeps history for the "needs attention" UI, matching how `Slide`
already soft-deletes.

### Pairing flow
- **Entity-leader side** (session-authed Inertia, new
  `EntitySlideAnnouncerController` alongside the existing
  [EntitySlideController](app/Http/Controllers/EntitySlideController.php),
  same `isAdmin() || isEntityAdmin($entity->id)` guard):
  - `GET /entity/{entity}/slide-announcers` — Inertia page listing paired
    devices.
  - `POST /entity/{entity}/slide-announcers/pairing-codes` — generates a
    6-digit code, 10-minute expiry, shown on-screen for the leader to key
    into the device.
  - `DELETE /entity/{entity}/slide-announcers/{slideAnnouncer}` —
    revoke/unpair.
- **Device side** (public, unauthenticated, `routes/api.php`):
  - `POST /api/slide-announcers/pair {code, device_name, mac_address?, device_uuid?, language?}`
    — validates the code (unused, unexpired), creates `SlideAnnouncer`
    (storing `mac_address`/`device_uuid` as sent), issues a Sanctum token
    (`abilities: ['slide-announcer']`), returns it once. `language` is the
    device's boot-yaml hint (its abbreviation, e.g. `en`); resolved to a
    `Language` and seeded onto `language_id` only if the device doesn't
    already have one assigned, so a re-pair never overwrites an entity
    admin's explicit choice. Generic error on
    bad/expired code (don't leak which). Rate-limited (`throttle:10,1` per
    IP, consistent with `routes/auth.php`'s existing pattern) plus a
    backoff-style `Cache`-backed hit counter (>20 attempts per IP in 10
    minutes → 429) on top of the route throttle, since this is the one
    endpoint on the whole API with no auth at all — decided this is good
    enough for v1 and tunable later without a design change if abuse
    patterns show otherwise. Also handles **re-pairing an existing
    `device_uuid`** (see below) by updating that row and revoking its old
    tokens, rather than always creating a fresh `SlideAnnouncer`.
  - **Re-pairing** (a device that already has a token, moving to a
    different site, or an explicit local "unpair" action) always goes
    through this same endpoint with a fresh code — there's no separate
    "transfer" API. Since generating a pairing code requires an
    authenticated entity leader/admin session on the website, re-pairing to
    a different site always requires a logged-in human's consent; a device
    can't silently retarget itself.

### Sync endpoint
`GET /api/slide-announcers/slides` (`auth:sanctum` + `slide-announcer.auth`).
**Flat full sync every poll, no incremental `since=` cursoring for v1** —
slide counts per site are small, nothing in the codebase already does
incremental fetch/cache-busting (frontend slideshow never re-fetches today),
and full sync is self-healing (device state can't drift). Revisit with an
`If-Modified-Since`/304 short-circuit only if scale demands it later.

Query reuses existing scopes directly:
```php
Slide::where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $slideAnnouncer->entity_id))
    ->current()
    ->orderBy('sort_order')
    ->get();
```
(composing `unscoped()`/`entityScoped()`, both of which already exist) —
**decided: devices see global + local slides mixed together, exactly like
the web slideshow does for a member of that entity** (mirrors
`visibleToUser()`'s semantics for a "user" that's pinned to exactly one
entity instead of possibly several). No language filtering (signage is a
fixed physical device, not a per-viewer preference).

Payload per slide: `id, file_url, thumbnail_url, mime_type, sort_order,
expires_at`. `file_url`/`thumbnail_url` come from `Slide`'s existing
accessors (`Storage::disk('public')->url(...)`) — these are disk-driver-aware
and work identically whether `public` is local or S3/R2, so the device gets
working URLs with no special-casing. (The `GenerateThumbnail` job's
local-filesystem-path usage is an orthogonal, pre-existing concern — it
doesn't affect what devices fetch.)

**Response shape includes presenter/display settings alongside slides**,
not just a bare slide array:
```json
{
  "settings": { "slide_duration_seconds": 10, "transition": "fade" },
  "slides": [ { "id": 1, "file_url": "...", "...": "..." } ]
}
```
`settings` is `SlideAnnouncer.settings` (the JSON column from the data model
above), editable per-device from the Entity/SlideAnnouncers page. No
per-slide duration field exists on `Slide` itself — a per-device default
(`slide_duration_seconds`) covers the "how long does an image show" need
without a schema change to `Slide`, and the JSON shape leaves room to grow
more display options later without another migration. The device caches
this alongside the slide manifest (see Tier 2's sync daemon) so it's
available even when offline.

### Heartbeat + version checks (app *and* OS)
`POST /api/slide-announcers/heartbeat` (`auth:sanctum` +
`slide-announcer.auth`), body `{app_version?, os_version?, architecture?, cpu_temp_c?}`.
`last_ip` is read from the request itself, not the body. Updates
`slide_announcers`' snapshot fields (`last_seen_at`, `last_ip`,
`last_cpu_temp_c`, `app_version`, `os_version`, `architecture`) **and** appends a row to
`slide_announcer_heartbeats` (see "New data model," above) so the same call
both refreshes the fleet-list view and extends its history. Expected to be
called on a 5-minute interval by the device (systemd timer, Part 2).
"Offline/needs attention" in the admin UI is computed, not stored:
`last_seen_at < now()->subMinutes(config('slide_announcer.online_threshold_minutes'))`
(default 12 — a little over two missed 5-minute beats — so one dropped
heartbeat from a transient network blip doesn't flip the badge).

Fold both the local-app update check and the RAUC OS update check into the
one heartbeat response (saves round trips, and the device already has to
call this endpoint regularly for liveness):
```json
{
  "ok": true,
  "latest_app_version": "1.4.0",
  "app_update_available": true,
  "app_download_url": "https://.../slide-announcer-1.4.0.tar.gz",
  "app_sha256": "…",
  "latest_os_version": "2026.08.1",
  "os_update_available": false,
  "os_bundle_url": "https://.../slide-announcer-2026.08.1.raucb",
  "os_bundle_sha256": "…",
  "os_auto_update_enabled": true
}
```
Both the app-update and OS-update fields are read from the same
`SlideAnnouncerRelease::resolveForDevice(kind, $slideAnnouncer->architecture, $slideAnnouncer->update_channel, $currentVersion)`
call, differing only in `kind` (`'app'` vs `'os'`) and which version
column feeds `$currentVersion` — which release a device is even offered,
of either kind, depends on its `update_channel` (stable/testing/developer,
set from the website), its self-reported `architecture`, and now also its
self-reported current version (a device that hasn't sent an architecture
yet simply matches nothing, same as any other no-current-release case).
`resolveForDevice()` first looks for a tagged hotfix whose
`required_base_version` exactly equals the device's current version, and
falls back to the tagged full release otherwise — a device several
versions behind picks up one hotfix hop per heartbeat rather than being
offered a multi-version jump in one response. `app_sha256` mirrors
`os_bundle_sha256` (added for the same integrity-check purpose the
`updater/`'s eventual smoke-check would want, once that's built).
`os_auto_update_enabled`
mirrors the device's own `auto_update_enabled` column: the server is the
source of truth for this switch (an entity leader/admin flips it from the
website, not on the device itself), and the device's update-check unit only
actually runs `rauc install` when this comes back `true` — otherwise it just
surfaces "update available" in its local status (and thus in the admin
fleet view via the device's own reporting) without installing, so
"automatic" vs "disabled" is centrally controlled per device. `os_bundle_url`
is a signed/expiring `Storage::url()` (or the plain public URL, same as
slide files) pointing at the `.raucb` bundle; the device's RAUC client is
invoked with that URL directly (`rauc install <url>` streams over HTTP, no
separate download step needed).

**Revocation is detected here, not just at pairing.** If a device's Sanctum
token has been deleted (entity leader revoked/unpaired it from the website),
every call to `/heartbeat` or `/slides` simply 401s like any invalid token
would — no special revocation payload needed. The device's local-app must
distinguish this from a transient network failure: a **network-level**
failure (timeout, DNS, connection refused) means "server unreachable," and
the device should keep showing its last-known-good cached slides
indefinitely (with the "needs attention" indicator, per Tier 2's sync
daemon) — but an **authenticated-and-rejected** response (401, meaning the
server was reached and explicitly said this token is no longer valid) means
"this device has been revoked," and should trigger a full local wipe
(delete `/data/device-token`, cached slides, settings, and manifest) and a
reboot straight back to the pairing screen. See "Device identity &
anti-clone protection" under Tier 2 for the other trigger of this same
wipe-and-reboot path, and "Kiosk display" for the explicit on-device unpair
action that also uses it.

### Admin/entity-leader visibility (device inventory = fleet management)
No separate "fleet management" system is needed — `slide_announcers` already
being entity-scoped means the two views you need are both simple queries
against it:
- **Per-site** (session-authed Inertia, entity leader): new
  `Entity/SlideAnnouncers` page next to the existing entity slide management
  page, same nav/guard as `EntitySlideController` — devices belong in the
  entity leader's "things about my site" surface. Shows device name,
  online/offline badge, `app_version`/`os_version`, `mac_address` (hardware
  inventory), paired-at, and the "generate pairing code" action. Per-device
  settings live here too: the `settings` JSON (slide duration, etc.) via a
  simple form, `update_channel` (stable/testing/developer) as a select, and
  `auto_update_enabled` as a toggle — plus the revoke/unpair action, which
  is the server-side half of the same wipe-and-reboot flow described under
  Heartbeat.
- **Cross-site** (platform admin, relevant once devices are spread across
  many churches/states): a new `Admin/SlideAnnouncerConsoleController` (or a
  tab folded into the existing `Admin/EntityConsoleController`) lists every
  `SlideAnnouncer` across every entity, joined to `Entity` for
  name/state/city, filterable by state/entity/online-status/version —
  exactly the "which devices, at which churches, are stale" view a platform
  admin needs, without running a separate fleet-management product.
- **Publishing a release** (OS or app): platform-admin-only (`Admin/*`,
  since a build is fleet-wide, not per-site) —
  `Admin/SlideAnnouncerReleaseController` + `Admin/SlideAnnouncerReleases.vue`,
  with a chunked-upload form (`kind`/`architecture`/`version`/`notes` +
  file, `sha256` computed server-side, optional "tag as" on publish) and,
  per release, tag/untag actions for each channel plus a details lightbox
  (SHA-256 and direct-URL copy buttons, a real Download link). This is the
  entire "rollout" mechanism — see Tier 1 below for why no scheduling/
  cohort logic is needed on top of it.

### New routes summary
```
web.php:
  GET    /entity/{entity}/slide-announcers
  POST   /entity/{entity}/slide-announcers/pairing-codes
  DELETE /entity/{entity}/slide-announcers/{slideAnnouncer}

  admin/slide-announcers                       (cross-site fleet view — not built yet)
  admin/slide-announcer-releases               (publish OS bundles + local-app archives, tag/untag channels)

api.php (new file, registered in bootstrap/app.php):
  POST   /api/slide-announcers/pair       (public, throttled)
  GET    /api/slide-announcers/slides     (auth:sanctum, slide-announcer.auth)
  POST   /api/slide-announcers/heartbeat  (auth:sanctum, slide-announcer.auth)
```

### Open questions to resolve before implementation
1. ~~Exact archive format for local-app releases~~ — resolved: `.tar.gz`,
   as originally proposed (see `slide-announcer:publish-release`, above).
2. Tagging is scoped by `(kind, architecture, channel)`, not by individual
   entity — fine for now; if the fleet grows enough that per-site/cohort
   rollout (beyond three fixed channels) becomes worth the complexity, an
   `entity_id` targeting column could be added to
   `slide_announcer_release_channels` (the tag, not the release itself —
   different entities could then see different current builds without
   duplicating the underlying file/row).
3. No rollback story yet for an OS release that boots fine (passes the
   health check, gets `mark-good`) but has a bug discovered later — today
   the only fix is publishing a new release. Current call: acceptable until
   real-world usage shows otherwise; revisit once there's field experience
   to design against instead of guessing.

### Future idea: multiple slideshows per site (not implemented)

Flagged as a direction worth documenting now, while the pairing/sync
contract is still young, even though nothing below is built:

Today a site (`Entity`) has exactly one slide deck, and every
`SlideAnnouncer` paired to that entity shows the same thing —
`SlideAnnouncerSyncController::index()` has no notion of "which show." A
church that wants, say, a lobby TV running general announcements and a
sanctuary-overflow TV running a different rotation (or the same slides in a
different order/duration) can't do that with one device-per-entity slide
scoping.

Sketch of what would need to change, if this gets built:
- A new `slideshows` table (`id, entity_id, name, is_default, ...`) that
  `Slide` gains an optional `slideshow_id` on (nullable — untagged slides
  fall back to the entity's default show, so existing single-slideshow
  sites need no data migration).
- `SlideAnnouncer` gains a `slideshow_id` FK (nullable — defaults to the
  entity's default slideshow, same fallback rule) so pairing a device
  optionally targets a specific show instead of "the entity's slides."
- `SlideAnnouncerSyncController::index()`'s query would scope by
  `slideshow_id` instead of (or in addition to) `entity_id` directly.
- The entity-leader UI (`Entity/Slides.vue` today, one flat list) would need
  a slideshow picker/tab, and per-device slideshow assignment would move
  into `Entity/SlideAnnouncers.vue`'s device settings alongside
  `update_channel`/`auto_update_enabled`.
- `settings` (per-device display options) already lives on `SlideAnnouncer`
  rather than a shared table, so per-device duration/transition overrides
  already compose fine with per-device slideshow assignment — no conflict
  there.

Deliberately not started: it's a real schema change (new table + FK on two
existing models) that only pays off once a site actually asks for more than
one show, and the current single-slideshow-per-entity model is simpler to
reason about everywhere it touches (sync query, admin UI, `Slide` scopes)
until that need is real.

---

## Part 2 — Device-side architecture ([`slideannouncer`](slideannouncer/) submodule)

**Status (2026-08-11): pairing + heartbeat + slide sync + all three update
tiers implemented** — `local-app/backend/pairing.py` (pairing client,
wipe-and-unpair), `heartbeat.py` (5-minute background task, reports
`app_version`/`os_version`/`architecture`/`cpu_temp_c`, handles 401
revocation), `sync.py` (60s slide sync daemon: polls
`GET /api/slide-announcers/slides`, downloads new/changed media, writes
`manifest.json`/`settings.json`/`active-playlist.json`, prunes locally
expired slides even while offline), `updater/local_app_updater.py` (reads
heartbeat's cached update-availability fields, downloads+smoke-checks+
atomically-swaps `/data/local-app/current`, restarts services, auto-reverts
on a failed post-restart health check), and `system/scripts/os-updater.py`
(reads the same cached heartbeat fields, `rauc install`s a resolved
hotfix or full-image release and, for a full image, triggers the existing
tryboot/health-check/commit cycle) all exist and are described below as
built. Both updaters share one idle-window gate (default 02:00–05:00
local) and run as independent, offset systemd timers; `os-updater.py`
additionally defers any OS-level change while `app_update_available` is
still true, so a local-app update and an OS reboot never land in the same
window on one device (see "Cross-tier update safety"). Hotfix-before-
full-image ordering needs no separate client logic at all —
`SlideAnnouncerRelease::resolveForDevice()` already only offers a hotfix
when its `required_base_version` exactly matches the device's current
`os_version`, falling back to the tagged full release otherwise, so the
device just installs whatever the server resolves, one hop per heartbeat.
`SlideAnnouncerHeartbeatController::releaseIsNewer()` (added 2026-08-11)
guards the `app`/`full`-OS case with a real `version_compare(..., '>')`
check, since an admin mistagging a lower-or-equal-versioned full release
used to read back as an "update" a device would install as a downgrade —
a hotfix's exact-base-match requirement already made this a non-issue for
that path. **Confirmed on real hardware (2026-08-16):** the kiosk
slideshow renderer reads `active-playlist.json` and displays a paired
site's real synced slides, not just a stub — the Menu key (or Esc)
toggles between the live slideshow and the on-device Settings screen.
**Confirmed on real hardware (2026-08-16): the Settings UI's "Update Now"
button works end-to-end for both tiers** — an OS hotfix install and a
local-app update each install and switch over cleanly from a single GUI
click, no console access needed. Getting there fixed several real bugs
surfaced along the way, none of them design changes: `os-updater.py` and
`local_app_updater.py` both read update-availability from heartbeat.py's
own up-to-5-minutes-stale background cache rather than the fresh result a
"Check for Update" click had just produced, so "Update Now" could
silently no-op right after finding a release; the app-tier updater
demanded exact string equality between the server's plain version tag and
a release tarball's own git-hash-suffixed `VERSION` content (`package.sh`
always adds the suffix), so no release its own tooling built could ever
pass; its extraction step didn't reapply `go+rX` the way
`local-app-seed.py`'s already did, so a release built with a restrictive
umask could extract unreadable to nginx; and the two tiers' shared
lock file and `/data/local-app` directory permissions were never
reconciled for both a root-run process and an unprivileged one writing to
them. **Still stubs:** Tier 1's tryboot/health-check/rollback path remains
verified only for the happy path on real hardware — the forced-bad-health
→ automatic-rollback case is still unverified (see that section's open
questions).

### Repo layout
```
slideannouncer/
├── image-builder/        # pi-gen + custom stage + OTA artifact packaging
├── local-app/             # backend (FastAPI) + frontend (setup + kiosk UI)
├── system/                 # systemd units, nginx conf, polkit rules
├── updater/                # local-app self-update client
├── provisioning/           # first-boot / AP-mode setup scripts
└── docs/
```
Three tiers map to three independently-versioned build outputs: an OS OTA
artifact, a local-app release tarball, and slide content (owned by the
server, not this repo).

### Tier 1 — Base OS image (rare updates, A/B OTA via RAUC, self-hosted)
- **Builder**: pi-gen (official RPi image builder), minimal `stage0`-`stage2`
  base plus a custom `stage-slide-announcer` injecting Chromium, a
  compositor, NetworkManager, nginx, the `rauc` client, and the device repo's
  systemd units/polkit rules.
- **Clean-before-compress stage** (critical, final export hook): strip SSH
  host keys (regenerate on first boot instead), reset `machine-id`
  (local + dbus, symlinked to regenerate on boot), clear bash history/apt
  cache/logs/tmp, zero free space before compressing. Never bake the RAUC
  *signing* key into the chroot — only the public verification certificate
  goes into the image's keyring; signing happens in CI, where the private
  key lives as a secret.
- **OTA: RAUC, self-hosted against this Laravel app** — chosen over Mender
  specifically because RAUC ships **no server component at all**: the
  client's job is just "fetch a manifest, compare versions, `rauc install
  <url>`," which is exactly the same shape as the Tier 2 app-update check
  already needs. Rather than standing up (or paying for) a second product —
  Mender Server, or RAUC's own HawkBit-based alternative — the "server" here
  is just the `slide_announcer_releases` table (`kind = 'os'`) and the
  heartbeat response fields described in Part 1, backed by the same S3/R2
  storage the slide files already use. This is the right tradeoff given the explicit
  goal of hosting everything from the existing site, and it scales fine
  across a multi-state fleet since there's still exactly one place
  (`Admin/SlideAnnouncerReleasesController`) to publish a release from —
  the "battery" you give up isn't a dashboard (the cross-site `Admin/*` view
  already covers that) or rollout scheduling (see below), just a
  purpose-built OTA product you'd otherwise be running two of.
- **Bundle format & partitioning**: `rauc bundle --cert=... --key=...`
  produces the signed `.raucb` artifact from the pi-gen output; `system.conf`
  in the image defines the A/B rootfs slot classes plus a persistent `/data`
  slot (RAUC's terminology for the same concept Mender calls a data
  partition). For the boot-side A/B switch, use the Raspberry Pi
  bootloader's native **tryboot** mechanism (supported by current
  `rpi-eeprom` firmware) rather than pulling in U-Boot — RAUC has a
  documented tryboot integration, and it's the more hardware-native, less
  moving-parts option on real Pi hardware.
- **Device-side update flow**: a small systemd timer/service (in `system/`,
  alongside the Tier 2 units) calls the heartbeat endpoint, and if
  `os_update_available`, runs `rauc install <os_bundle_url>` (RAUC streams
  and verifies the signature against the baked-in cert as it installs — no
  separate download-then-verify step), then reboots into the new slot via
  tryboot. Confirmed on real hardware that a tryboot-*flagged* boot's GPU
  init is unreliable (a Raspberry Pi firmware quirk, not something this
  project's config controls), so a tryboot session is only ever a brief,
  headless verification window — kernel boots, root mounts, system comes
  up — never expected to show anything on screen. Only after that health
  check passes does the unit call `rauc status mark-good` and flip
  `config.txt`'s permanent `os_prefix=` to the new slot (not a file copy),
  then immediately reboot normally so the device actually starts using
  the new slot's working display. If the check fails, tryboot's own
  fallback returns the device to the previous slot on the next boot
  automatically — the same auto-rollback property Mender would have given,
  achieved through RAUC's native mechanism instead.
- **Read-only rootfs**: `rootA`/`rootB` are mounted `ro` (kernel cmdline +
  `/etc/fstab`) — a device that loses power mid-write to an ext4 rootfs it
  isn't otherwise touching has nothing to gain from that risk, and RAUC
  already replaces rootfs wholesale on update rather than patching it in
  place. `/tmp` and `/var/tmp` are plain volatile tmpfs. `/etc` and `/var`
  each get a CoW overlay (`lowerdir=` the real, read-only directory,
  mounted back over itself) so services can still write to the paths they
  expect: `/var`'s upper layer is tmpfs (logs, nginx/NetworkManager runtime
  state, caches — nothing there needs to survive a reboot), while `/etc`'s
  upper layer lives on `/data` — SSH host keys, `machine-id` (both
  regenerated once by `provisioning/firstboot.py`), and any future
  NetworkManager connection profiles under
  `/etc/NetworkManager/system-connections/` need to, and this way they just
  do, as plain file writes, no extra persistence code required. Tradeoff:
  an OTA that changes a stock `/etc` file already shadowed by something in
  the upper layer won't show through until that shadow is cleared —
  acceptable since nothing here expects a rootfs update to silently rewrite
  live `/etc` config out from under a running device. See
  `image-builder/stage-slide-announcer/01-system-files/00-run.sh` (fstab/
  cmdline wiring) and `system/slide-announcer-data-dirs.service` (creates
  `/etc`'s upper/work dirs on `/data`, at boot rather than at image-build
  time — since they must exist before the very first boot's overlay mount
  *and* after a factory reset reformats `/data` at runtime, one boot-time
  mechanism covers both instead of a build-time seed a reset would bypass).
- **`slideadmin`'s home, bind-mounted onto `/data`** (added 2026-08-16):
  the `slideadmin` console/SSH account (see "First-boot / network setup
  flow" below for why it exists) had the same problem as `/etc` — plain
  root is `ro`, so bash history and anything else written under
  `/home/slideadmin` reset on every reboot, and OTA replaces it outright.
  Fixed the same way as `/etc`, just with a bind mount instead of an
  overlay (nothing here needs to fall back to reading rootfs's original
  skeleton once `/data` is populated, so the simpler primitive is enough):
  `slide-announcer-home-dirs.service` creates and seeds (from `/etc/skel`,
  once) `/data/home/slideadmin` before `/home/slideadmin` mounts onto it,
  `nofail` so a `/data` mount failure falls back to rootfs's own original
  home rather than an emergency shell. This is also what makes SSH key
  rotation config-driven instead of build-time-baked — see
  "First-boot / network setup flow" below, `slideannouncer.yaml`'s
  `ssh_authorized_keys` field.
- **Fixed (2026-08-10): swap's file half was on tmpfs, not disk.** Current
  Raspberry Pi OS doesn't use `dphys-swapfile` (this section's original
  draft assumed it did, and even briefly misdiagnosed the fix around it —
  the package isn't installed on this image at all); the real mechanism is
  `rpi-swap`, a zram + file-backed-overflow hybrid (`dev-zram0.swap`, "rpi-swap
  managed swap device (zram+file)"). The zram half is fine — RAM-backed by
  design — but the file half defaulted to `/var/swap`, and once `/var`'s
  upper layer became tmpfs (see "Read-only rootfs" above), that file was
  silently backed by RAM, not disk. Confirmed on real hardware:
  `rpi-resize-swap-file.service` creates it at full configured size on
  every boot regardless of usage — a freshly booted device had ~375MB
  resident in `/run/overlay-var/upper` for a file `free` reported as 0
  used swap. Under real memory pressure this would have made things
  *worse*, not better. Fixed by repointing the file half at a fixed
  1536MB file on `/data` via an `/etc/rpi/swap.conf.d/` drop-in
  (`slideannouncer/system/read-only-root/rpi-swap-data.conf`, wired into
  `01-system-files/00-run.sh`; see `system/README.md`) — no manual
  ordering against `data.mount` needed, since `rpi-swap-generator` derives
  each unit's `RequiresMountsFor=` from the drop-in's `Path=` fresh on
  every boot. Shipped as hotfix 0.1.5 for devices already in the field,
  which also tears down the old `/var/swap`-backed device live to free
  that RAM immediately rather than waiting for a reboot. Fixed size rather
  than auto-sized, for the same SD-card write-endurance/random-I/O reasons
  this section originally raised about heavy swap reliance on a kiosk.
- **Persistent state discipline**: anything that must survive an OS update
  and needs Unix semantics (symlinks for atomic app-release swaps, `chmod
  600` on the pairing token and the identity secret, synced slide media,
  sync-status file) lives on the persistent ext4 `/data` slot, never on
  rootfs — rootfs is replaced wholesale on each RAUC install. The
  exceptions are `slideannouncer.yaml` (declared device identity, the
  `server_url` this device talks to, and initial-setup hints) and
  `network-config` (WiFi/network settings, via
  cloud-init's own NoCloud mechanism), which deliberately live on the
  separate FAT32 boot/firmware partition instead — see "First-boot /
  network setup flow" and "Device identity & anti-clone protection" under
  Tier 2 for why (human-editable from a PC/Mac; that partition is
  untouched by RAUC updates too, so it's just as persistent as `/data` for
  this purpose). Systemd units reference `/data`- and `/boot/firmware`-
  relative paths so both are unaffected by OS upgrades.
- **Update safety (no Sunday-morning surprises, without a scheduling
  server)**: since there's no Mender-Server-style deployment scheduler, the
  "don't reboot mid-service" safeguard moves entirely to the device, using
  the same idle-window gating Tier 2 already needs — the OS-update check in
  the heartbeat-polling systemd unit only *acts* on `os_update_available`
  during the configured idle window (e.g. 02:00–05:00 local), even though
  it can see the flag any time. One mechanism now covers apply-time gating
  for both update tiers instead of needing tier-specific scheduling logic.

### Tier 2 — Local web app (frequent updates, no A/B needed)
- **Stack**: FastAPI backend (async I/O suits the polling sync loop; mature
  `nmcli`-based NetworkManager control) as a set of small systemd services —
  separate `slide-announcer-backend` (WiFi/pairing HTTP API) and
  `slide-announcer-sync` (slide sync daemon) units, so a crash in one
  doesn't take down the other. nginx serves static frontend assets and
  locally-cached slide media directly, reverse-proxying `/api/*` to FastAPI
  on loopback only.
- **Update mechanism — atomic symlink swap, deliberately simpler than the OS
  tier's A/B scheme**: a bad app update can't brick the device the way a bad
  OS update can, and `ln -sfn` is already atomic. `updater/` polls the
  `GET /api/slide-announcers/heartbeat` response (or a dedicated version
  endpoint) for `update_available`/`download_url`, downloads to
  `/data/local-app/releases/<version>/`, smoke-checks it, swaps
  `/data/local-app/current` to point at it, restarts the two services. If
  the restarted service fails its own health check, auto-revert the symlink
  and restart again — no dual-partition infra required. Keep the last 2-3
  releases on disk for instant rollback.
- **Apply-time gating**: version checks can run continuously, but the actual
  download+restart should be gated to a configurable idle window (e.g.
  02:00–05:00 local) since restarting the backend causes a brief kiosk
  hiccup — the kiosk frontend should tolerate a momentary local-status fetch
  failure without immediately flashing the "needs attention" icon.
- **Initial install and OS-update seeding reuse the same `/data` +
  `current` layout, not a separate rootfs install.** local-app is never
  baked into rootfs directly — only its packaged release tarball, at a
  fixed read-only path (`/opt/slide-announcer/local-app-release/`). A
  boot-time script (`system/scripts/local-app-seed.py`, implemented) runs
  before the backend/kiosk services on every boot and extracts that
  tarball onto `/data/local-app/releases/<version>/` + swaps `current` —
  but **only if `/data` has no local-app installed yet, or an older one
  than what's embedded in this image; it never downgrades** (comparing
  only the release's `X.Y.Z`, not its git-hash build suffix). This one
  mechanism covers both "fresh card" (nothing installed, seed
  unconditionally) and "a RAUC OS update ships a newer local-app than
  what's on `/data`" (picked up automatically on the reboot into the new
  slot, no separate app-update round trip) — while guaranteeing an OS
  update can never silently regress a device that already got a *newer*
  app from `updater/`'s own OTA path back down to what an older OS image
  happened to ship. The venv the backend runs in
  (`/opt/slide-announcer/venv`) is fixed OS-image infrastructure, built
  once from the release's `requirements.txt` at image-build time —
  independent of whichever release `current` points at, on the assumption
  that app-only updates (no OS reflash) stay code-only.

### First-boot / network setup flow
- Backend drives NetworkManager via `nmcli` subprocess calls (not raw D-Bus
  bindings — more stable, testable), running as a dedicated non-root user
  authorized via a polkit rule.
- The setup UI itself (`local-app/frontend/setup`) is a single local web
  page regardless of how it's reached — it's just served by nginx on the
  device. That matters because it means the *input path* and the *display
  path* to that page are independent choices, not two different UIs to
  build.

Three setup modalities exist, tried in order at boot:

**0. Pre-provisioned config files — true headless.** Two plain-text files
at the root of the Pi's existing FAT32 boot/firmware partition — the same
well-established mechanism Raspberry Pi OS itself uses for headless setup
(dropping `wpa_supplicant.conf`/an empty `ssh` file onto that partition
before first boot). FAT32 is the right home for both rather than a new
dedicated partition: no driver needed on any OS (Mac, Windows, Linux) to
read/write, tiny files that never approach its 4GB file-size limit, and a
partition that already exists in the image rather than an addition to an
already-nontrivial A/B + `/data` layout. Because it's plain FAT32 (no
symlinks, no Unix permissions), it only ever holds things that are safe to
be world-readable and don't need atomic symlink swaps — network settings
and the *declared* device identity, not the local-app releases or the
pairing token (those still need `/data`, ext4, exactly as designed
before).

- **`network-config`** — a standard netplan-format file, applied by
  cloud-init's NoCloud datasource before anything of this project's own
  code runs at all. Deliberately NOT this project's own bespoke format:
  netplan's own reference covers static IP/gateway/DNS, multiple access
  points, enterprise WiFi (EAP), etc. — reusing a well-documented existing
  format beats inventing and maintaining a parallel one that would only
  ever cover a fraction of it. `image-builder`'s build ships a commented
  example (DHCP and static-IP variants) here by default.
- **`slideannouncer.yaml`** — identity, the server this device talks to,
  and initial-setup hints only, never network settings:
  ```yaml
  default_language: en
  server_url: https://your-server.example.org
  ssh_enabled: true
  ssh_authorized_keys: |
    ssh-ed25519 AAAA...
  device_uuid: "3f29b6d2-....-....-...."
  device_uuid_check: "a1b2c3...(hex)"
  ```
  `default_language` ("en"/"es") is read once, purely as a hint for the
  very first setup screen — changing the language later from the device's
  own Settings menu has no effect on this file. `device_uuid`/
  `device_uuid_check` should normally be left out entirely; see "Device
  identity & anti-clone protection" below for why. `server_url`,
  `ssh_enabled`, and `ssh_authorized_keys`, unlike those two, ARE re-read
  every boot (and, for `ssh_enabled`, on every `ssh.service` start
  attempt; for `server_url`, on every pairing/sync/heartbeat/OTA-check
  request), not just the first: `server_url` is read directly by
  `local-app/backend/pairing.py`'s `read_server_url()` and
  `system/scripts/rauc-update.py`'s own copy of the same logic (no
  build-time file involved — see `image-builder/build.sh`, which only
  optionally *seeds* a default into this yaml at build time, and fails
  closed if absent so a half-configured device never silently no-ops);
  `ssh_authorized_keys` is applied to `slideadmin`'s
  `~/.ssh/authorized_keys` on every boot
  (`provisioning/firstboot.py`'s `sync_ssh_authorized_keys()`), and
  `ssh_enabled` gates whether sshd is even allowed to start at all
  (`system/ssh/ssh-gate.conf`'s `ExecStartPre=`, checking this field via
  `system/scripts/ssh-gate.py` — `ssh.service` itself is
  `systemctl enable`d in every image unconditionally now, so this is the
  real on/off switch). Editing any of these and rebooting (or, for
  `server_url`, just restarting `slide-announcer-backend`) an
  already-deployed device moves it to a different server, turns SSH
  on/off, or rotates/revokes its key — no rebuild/reflash needed. This is
  also what lets a single image/RAUC bundle serve multiple independent
  servers: build once, then set `server_url` per device by swapping this
  file instead of rebuilding — see "`slideadmin`'s home, bind-mounted onto
  `/data`" above for
  what makes the key half of that persist at all (the `ssh_enabled` gate
  itself doesn't need `/data` — it only ever reads the boot partition).

On boot, `provisioning/firstboot.py` polls NetworkManager for an already-
active connection (i.e. cloud-init's `network-config` already succeeded)
to label the detected setup mode `headless-config` — it doesn't itself
join WiFi or read credentials from anywhere; cloud-init already did that
before this project's own code ever ran. This is genuinely zero-touch: a
technician can hand-edit both files (or a provisioning script can generate
them) before a card is ever put in a device or shipped anywhere, closing
the "true headless" gap flagged earlier. Pairing itself still needs a
fresh code from the website (codes are short-lived by design), so this
path removes the network-setup step from zero-touch provisioning, not the
pairing step.

**1. On-device setup via an attached HID input** (e.g. an RF remote
presenting as a keyboard/mouse combo), if no usable config file was found.
Confirmed on real hardware: the remote presents to the OS as a plug-and-play
USB HID keyboard/mouse combo, not Bluetooth — it works *before* WiFi exists
and before any pairing has happened, with no Bluetooth pairing step of its
own required.
- The backend checks for a usable HID input device (`/dev/input/event*`
  exposing keyboard + relative-pointer capabilities, e.g. via
  `evtest`/`libinput` introspection rather than assuming a specific device
  path).
- If found: `slide-announcer-kiosk.service` points Chromium straight at
  `http://localhost/setup` on the device's own display. The admin uses the
  remote to pick a network from a list (`nmcli device wifi list`) and type
  the password directly into the on-screen form — real key events into a
  real page, no on-screen keyboard needed. Once connected, the same display
  flips to the **pairing screen** to accept the numeric code, then to
  `kiosk`. No captive portal question exists on this path at all, since
  there was never a second network/device in the loop to trigger one.

**2. Fallback — headless AP-mode (no config file, no HID input).** Falls
back to the already-designed AP-mode flow: NetworkManager's built-in
hotspot (`nmcli device wifi hotspot`, avoids needing hostapd/dnsmasq) with a
fixed SSID → admin connects with a phone, browses to a printed IP → enters
WiFi credentials → backend connects via `nmcli`, tears down the hotspot →
pairing screen → `POST /api/slide-announcers/pair` → `kiosk` mode. True
captive-portal auto-popup on this path is still explicitly deferred — the
printed-IP flow is accepted as good enough for now.

All three paths converge on the same pairing screen and the same
`POST /api/slide-announcers/pair` call once WiFi is up.
- Common to both paths: once WiFi credentials are stored, revisiting either
  path later requires an explicit admin action (see the local settings
  menu's "reset network"/unpair actions) — the device does **not**
  auto-fall-back into setup mode on a dropped connection (that would
  interrupt a live slideshow on a false-positive blip); a lost connection
  instead just shows the stale-cache indicator.

### Device identity & anti-clone protection
`device_uuid` is deliberately **visible and human-editable** — it lives in
`slideannouncer.yaml` on the FAT32 boot partition (see First-boot flow,
above), so a technician can read or hand-set it off-device. That visibility
is exactly why the identity check can't just trust whatever UUID is written
there: the actual tamper-resistant half of a device's identity is a secret
that never leaves the ext4 `/data` partition.

**Two files, two roles:**
- `/boot/firmware/slideannouncer.yaml` (FAT32, world-readable/editable):
  `device_uuid` and `device_uuid_check` — a value anyone can *read*, but
  can't *forge* without the secret below.
- `/data/identity.key` (ext4, `chmod 600`, owned by the backend service
  user): a random secret `identity_key`, generated once and never written
  anywhere else — not to the boot partition, not sent to the server, not
  reconstructable from anything visible outside `/data`.

**The check:** `device_uuid_check = HMAC-SHA256(identity_key, device_uuid + mac_address)`,
computed and written to the boot config whenever a device's identity is
(re)established — first boot, a successful re-pair, or a wipe/regenerate.
On every subsequent boot: read `device_uuid`/`device_uuid_check` from the
boot partition, read `identity_key` from `/data`, recompute the HMAC using
the *currently detected* MAC address, and compare:
- **Match** → the declared UUID, the secret key, and the current hardware
  are all consistent with each other → proceed normally.
- **Mismatch** → wipe and re-pair, unconditionally (regenerate a fresh
  `device_uuid` *and* a fresh `identity_key`, delete the pairing token and
  cached slides/settings, write a new consistent UUID/check pair back to the
  boot config, boot into the pairing screen) — the same wipe-and-reboot path
  revocation and explicit unpair use (see Heartbeat and Kiosk display,
  above).

This one check now covers every way the identity could go wrong, without
needing separate cases for each:
- **Hardware actually changed** (dead Pi board, same SD card moved to a
  replacement unit, or a genuine SD-card clone onto second hardware) — the
  MAC used to compute the stored check no longer matches, so the HMAC
  recomputation fails regardless of what UUID is on record. Per your
  decision above, this always wipes and re-pairs rather than trying to
  distinguish "legitimate swap" from "clone" — a site admin re-entering a
  pairing code after a hardware swap is an acceptable, infrequent cost for
  never having a manual bypass that could be misused the other way.
- **Someone edits `device_uuid` by hand** on the boot partition (whether
  out of curiosity, to relabel a device, or to try to impersonate another
  device's identity) — without `identity_key` (which never leaves the
  original `/data`), they cannot compute a `device_uuid_check` that matches
  their edited UUID, so the very next boot fails the check and wipes back to
  a fresh, unpaired identity. The edit doesn't grant a forged identity; it
  just forces a re-pair.
- **A full clone of both partitions** onto a second physical unit — the
  clone carries a valid, matching UUID/check/key triple, but its MAC
  differs from the one baked into the check, so it still fails and
  wipes/regenerates independently on first boot, exactly as before.

### Slide sync daemon
- 60s baseline poll of `GET /api/slide-announcers/slides`. Local manifest
  (`/data/slides/manifest.json`, keyed by slide id + `updated_at`) drives a
  simple diff: download new/changed files to a temp path then atomically
  `os.replace()` into place; remove files whose id disappeared from the
  server response or whose `expires_at` has locally passed (so expiry still
  works offline); respect `publish_at` by pre-caching future slides but
  excluding them from the active playlist until due. The response's
  `settings` object is written alongside the manifest (e.g.
  `/data/slides/settings.json`) on every successful sync, so the kiosk
  frontend always has the latest presenter/display settings cached locally
  too.
- On a request failure, the daemon must distinguish *why* it failed:
  network/timeout errors leave the last-synced manifest, media, and settings
  untouched — the slideshow keeps playing from cache, indefinitely if
  necessary, with only the local "needs attention" indicator reflecting the
  staleness. A 401 (token rejected) is different in kind, not degree — see
  Heartbeat's revocation handling above — and triggers a full wipe rather
  than just marking the cache stale.
- Writes `sync-status.json` (`last_success_at, last_attempt_at, last_error`)
  after every cycle. The kiosk frontend polls a **local-only**
  `GET /api/local/status` (loopback, no internet dependency) to decide
  whether to show the "needs attention" corner icon — this is what makes the
  indicator work correctly even while offline.

### Kiosk display
- **Decided: Wayland (labwc, or `cage`) over classic X11** — matches where
  Raspberry Pi OS Trixie+ already defaults, and current Chromium has
  better Wayland/Ozone support than X11 at this point. **Confirmed on real
  hardware (2026-08-15):** a freshly-imaged device now boots all the way
  to the kiosk display (labwc + Chromium) end-to-end. Getting there
  surfaced (and this project fixed) several concrete first-boot bugs
  along the way, none of them about Wayland/labwc itself — see
  `slideannouncer`'s commit history around 2026-08-13/15 for the specifics
  (`/boot/firmware` mounted read-only by default plus every writer that
  needed bracketing for it, `/data`'s partition grown before it's
  formatted rather than after so `mke2fs` sizes it correctly the first
  time, and a couple of first-boot systemd ordering races: `growpart`'s
  own `/tmp` scratch dir vs. `tmp.mount`, and
  `rpi-resize-swap-file.service`'s fixed-size swapfile vs. `/data`
  actually being grown yet). **Confirmed 2026-08-16:** the full loop
  works, not just the boot — once paired, a device displays that site's
  real synced slides on the kiosk display, and the Menu key (or Esc)
  toggles between the live slideshow and the on-device Settings screen.
- `slide-announcer-kiosk.service` starts the compositor then execs
  `chromium --kiosk --ozone-platform=wayland ... --app=http://localhost/kiosk`,
  pointed only at the local nginx-served app — never the remote server
  directly, so the slideshow keeps running offline.
- Kiosk frontend renders from a locally-written `active-playlist.json`
  (derived by the sync daemon from the manifest + `sort_order`) and the
  cached `settings.json` (slide duration, transition, etc.), referencing
  nginx-aliased local media paths. The "needs attention" overlay is a small
  fixed-position element in the same bundle, shown/hidden purely by polling
  the local status endpoint above.
- **Local settings menu, including explicit unpair**: a small on-device
  settings screen (reachable via a fixed gesture/URL on the kiosk display,
  not exposed on the main slideshow) exposes device-local diagnostics and an
  explicit "Unpair this device" action. Unpairing runs the exact same local
  wipe (token, cached slides, manifest, settings) and reboot-to-pairing-
  screen sequence that a server-side revocation or a MAC-mismatch triggers —
  one code path, three triggers. Re-pairing afterward — to the same site or
  a different one — always requires a fresh code generated by a logged-in
  user on the website (see Pairing flow, Part 1), so physical access to a
  device is never enough on its own to move it to a different site.
  **Implemented ahead of pairing itself**: a broader "Factory Reset" action
  in the real Settings > System menu (`local-app/frontend`) reformats
  `/data` entirely and reboots — a superset of the unpair flow described
  above (also clears WiFi credentials and device identity, not just
  pairing/slide state), for testing and for a device that needs to start
  over completely. See `slideannouncer/system/scripts/factory-reset-check.sh`
  and `local-app/README.md`'s "Privileged operations from the web UI."
  Once pairing exists, "Unpair this device" should probably stay a
  separate, lighter action from this — it doesn't need to take WiFi down
  with it.
- **Settings PIN (optional, low-effort deterrent, not real access
  control)**: an entity leader can set a 4-6 digit PIN on
  `Entity/SlideAnnouncerShow.vue`, stored in the same per-device `settings`
  JSON blob `interval_seconds` already lives in (`settings_pin` key) — no
  new backend endpoint, it rides the existing sync endpoint down to the
  device just like every other setting. Frontend (`local-app/frontend`):
  a `router.beforeEach` guard in `router.js` checks `settings_pin` (via the
  same `/api/local/slideshow` response the kiosk already polls) whenever
  navigation enters `/settings` from outside it, and — if a PIN is set and
  this in-memory session (`pinLock.js`) hasn't already cleared it —
  redirects to a full-screen `/pin-lock` route (`PinGate.vue`) instead.
  Entering the correct PIN unlocks the session and continues to the
  originally-requested settings screen; getting it wrong just clears the
  attempt. Failing to enter it within 15 seconds of the gate appearing
  bounces back to `/kiosk` — the countdown doesn't reset per attempt.
  Leaving the `/settings` section back out to the kiosk re-locks the
  session, so the PIN is required again next time. Deliberately no backend
  validation, hashing, or rate-limiting — this is meant only to stop
  someone with the remote from casually opening settings, not to resist a
  determined attacker with the PIN unknown to them but console access.

### Cross-tier update safety
No tier blocks another — local-app updates and slide sync both continue
through an OS update since `/data` is untouched by a rootfs swap. Avoid
scheduling an OS update and a local-app update in the same maintenance
window on one device, to keep failure attribution simple.

### Open questions / tradeoffs flagged
1. RAUC's tryboot integration is now hardware-validated end-to-end
   (2026-08-10): `rauc install` over HTTP (verity/streaming), install onto
   the inactive rootfs+kernel slots, `reboot "0 tryboot"`, boot into the
   staged slot, and commit all confirmed working on a real Pi 4. The
   hotfix path (no A/B involved) was confirmed the same day — version-gate
   check, live-rootfs write-through, on-device VERSION bump.
   Along the way, real hardware surfaced (and this project fixed) several
   concrete bugs the original design doc's guesses got wrong: RAUC's
   custom bootloader backend exchanges *bootnames* ("A"/"B"), not slot
   names; `root=LABEL=...` doesn't reliably resolve during a tryboot boot
   (switched to `root=PARTUUID=`, resolved dynamically per-device at
   install time); and — the significant one — **a tryboot-*flagged*
   `os_prefix` boot's DTB-fixup step silently fails to apply the
   `vc4-kms-v3d` overlay** (zero DRM devices, no kiosk display), while the
   identical files loaded via a *permanent*, non-tryboot `os_prefix` boot
   with a working GPU every time. That's a Raspberry Pi firmware quirk
   specific to the tryboot flag, not to `os_prefix` in general, and it
   reshaped the design: `config.txt` now carries a permanent
   `os_prefix=slotA/` (or `slotB/`) line that every *normal* boot reads;
   `slotA`/`slotB` (populated at build time / by each OTA install) are the
   *only* copies of kernel/initramfs/`.dtb`s/overlays/`cmdline.txt` — no
   third "promoted to partition-root" copy anymore. A tryboot session is
   now used only for a brief, headless-acceptable verification window (does
   the kernel boot, does root mount, does the system come up); on success,
   `rpi-tryboot-commit.sh` flips `config.txt`'s `os_prefix=` line (not a
   file copy) and immediately reboots normally, so the device starts using
   the new slot's working GPU/kiosk right away rather than sitting on the
   tryboot-flagged session any longer than the health check needs. See
   `slideannouncer/image-builder/repartition.sh` and
   `slideannouncer/system/rauc/rpi-tryboot-commit.sh` for the full
   rationale. The post-update health check is still just a placeholder
   ("did we reach this systemd unit") — a real check of network/backend/
   sync health is the next concrete gap here, not hardware validation.
   Reconfirmed 2026-08-13 on a paired production device doing a real
   0.1.10 → 0.2.1 field OTA: `install` + `tryboot --yes` came up correctly
   on 0.2.0 and resumed slide sync/display, and a subsequent power cycle
   stayed on 0.2.0 — the commit (not just the tryboot boot) survives a
   normal reboot.
2. Symlink-swap (no A/B) for the local-app tier — revisit only if future
   local-app releases start needing local-state migrations a plain swap
   can't cleanly roll back.
3. Exact archive format for local-app releases (`.tar.gz` proposed) — to be
   finalized next.
4. ~~True headless configuration~~ — resolved: the pre-provisioned
   `network-config` on the boot partition (cloud-init's own NoCloud
   mechanism) covers zero-touch WiFi setup, alongside `slideannouncer.yaml`
   for identity (see First-boot flow, above). Pairing itself still needs a
   fresh code generated on-demand from the website, since codes are intentionally
   short-lived — fully zero-touch *pairing* (no human action at all once a
   device is powered on at its site) is still an open question if that ever
   becomes a requirement.
5. ~~Confirm the RF remote hardware is a plug-and-play HID dongle, not
   Bluetooth~~ — resolved: confirmed on real hardware, the remote presents
   as a USB HID keyboard/mouse combo, so input works before any pairing
   (WiFi or Bluetooth) has happened.
6. `device_uuid_check` uses HMAC-SHA256 in this design — HMAC (not a bare
   hash of the concatenation) is the part that matters, since it's what
   makes the check unforgeable without `identity_key`; SHA-256 itself is
   just a reasonable, unremarkable choice of underlying hash.
7. **Not yet implemented: self-healing `/data` on corruption/fsck failure.**
   `/data` currently mounts `nofail` with no automatic fsck (`passno=0` —
   see `slide-announcer-data-resize.service`'s own comment for why an
   auto-generated `systemd-fsck@...` unit is actively dangerous here, it
   raced the factory-reset reformat and caused a real first-boot hang).
   That means a genuinely corrupt `/data` today just fails to mount and
   stays failed — `nofail` keeps the boot from dropping to an emergency
   shell, but doesn't get the kiosk/backend back up either, since both
   depend on `/data` being real (identity, pairing, cached slides). For an
   unattended device with no local user and often awkward physical access,
   silently staying broken forever until someone notices and re-images is
   a bad failure mode.
   Proposed design (discussed 2026-08-14, not built): don't wipe on a bare
   mount failure — react to it (e.g. an `OnFailure=` unit on `data.mount`,
   or a single script that tries mount → `fsck.ext4 -y` → retry the mount
   → only *then* wipe) so a repairable case (the common one — SD card was
   mid-write during an unclean power-off, which this hardware sees often)
   gets fixed via fsck first, and a full reformat (identical to the
   existing `FACTORY_RESET` path) is the last resort, not the first
   response. Also needs some marker that survives the wipe long enough to
   report "auto-recovered from `/data` corruption" on the device's next
   check-in — without that, a card that's actually failing repeatedly just
   reads as an unremarkable string of factory resets from the fleet's point
   of view, and nobody notices the hardware is dying until it stops
   recovering at all.

---

## Suggested implementation order

1. **Part 1 (server API)** first, in isolation — it's independently testable
   via `curl`/Postman against a dev server before any device hardware exists,
   and gives the device side a real contract to build against.
2. **Tier 2 (local-app)** next, running directly on a Pi with a manually-
   flashed stock Raspberry Pi OS — proves the WiFi/pairing/sync/kiosk flow
   before investing in the pi-gen/RAUC image pipeline, which is the most
   complex and highest-setup-cost piece.
3. **Tier 1 (image builder + RAUC OTA)** last, once the app it's shipping
   is stable enough to be worth the OTA investment.

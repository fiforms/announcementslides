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

**Status: architecture only. No application code has been written yet** —
the Sanctum wiring, migrations, controllers described in Part 1, and the
device firmware described in Part 2 are planned here but not implemented.

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
update_channel (enum: stable/testing/developer, default stable),
auto_update_enabled (bool, default true), settings (json, nullable),
last_seen_at, last_ip, paired_at, paired_by (FK users), revoked_at,
timestamps`. Relationships: `Entity::slideAnnouncers()` hasMany,
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
  leader or admin sets from the website — they drive which
  `slide_announcer_os_releases` row a given device is offered (by channel)
  and whether its own update-check unit is allowed to actually install it
  or just report availability (see Heartbeat, below).
- `settings` is a free-form JSON blob (display duration, transition style,
  and future per-device display options) — see "Presenter/display
  settings," below.

**`slide_announcer_pairing_codes`**: `id, code (unique, 6-digit numeric),
entity_id (FK), created_by (FK users), expires_at, used_at,
slide_announcer_id (nullable FK, set on consumption), timestamps`.

**`slide_announcer_os_releases`** (new, for the RAUC rollout — see Tier 1):
`id, version (string), channel (enum: stable/testing/developer),
bundle_disk_path, sha256, is_active (bool), notes, released_at, created_by
(FK users), timestamps`, with `is_active` scoped **per channel** (unique
constraint on `channel` where `is_active = true`) rather than one global
switch — a device only ever compares against the active release on its own
`update_channel`. This is what makes "developer"/"testing"/"stable" tiers
work: publish a build to `developer` first, promote the same or a newer
build to `testing`, then to `stable`, each a separate row activation. The
bundle file itself lives on the same `Storage` disk (S3/R2 in prod) as
everything else, so publishing a release is "upload a file + flip a flag,"
no separate hosting.

Revocation is `revoked_at` + deleting the device's Sanctum tokens, not a hard
delete — keeps history for the "needs attention" UI, matching how `Slide`
already soft-deletes.

### Pairing flow
- **Entity-leader side** (session-authed Inertia, new
  `EntitySlideAnnouncerController` alongside the existing
  [EntitySlideController](app/Http/Controllers/EntitySlideController.php),
  same `isAdmin() || isEntityAdmin($entity->id)` guard):
  - `GET /entities/{entity}/slide-announcers` — Inertia page listing paired
    devices.
  - `POST /entities/{entity}/slide-announcers/pairing-codes` — generates a
    6-digit code, 10-minute expiry, shown on-screen for the leader to key
    into the device.
  - `DELETE /entities/{entity}/slide-announcers/{slideAnnouncer}` —
    revoke/unpair.
- **Device side** (public, unauthenticated, `routes/api.php`):
  - `POST /api/slide-announcers/pair {code, device_name, mac_address?, device_uuid?}`
    — validates the code (unused, unexpired), creates `SlideAnnouncer`
    (storing `mac_address`/`device_uuid` as sent), issues a Sanctum token
    (`abilities: ['slide-announcer']`), returns it once. Generic error on
    bad/expired code (don't leak which). Rate-limited (`throttle:10,1` per
    IP, consistent with `routes/auth.php`'s existing pattern) plus a
    backoff-style `RateLimiter` hit counter, since this is the one endpoint
    on the whole API with no auth at all — decided this is good enough for
    v1 and tunable later without a design change if abuse patterns show
    otherwise.
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
`slide-announcer.auth`), body `{app_version?, os_version?}`. Updates
`last_seen_at`, `last_ip`, and both version fields. "Offline/needs attention"
in the admin UI is computed, not stored: `last_seen_at < now()->subMinutes(3)`.

Fold both the local-app update check and the RAUC OS update check into the
one heartbeat response (saves round trips, and the device already has to
call this endpoint regularly for liveness):
```json
{
  "ok": true,
  "latest_app_version": "1.4.0",
  "app_update_available": true,
  "app_download_url": "https://.../slide-announcer-1.4.0.tar.gz",
  "latest_os_version": "2026.08.1",
  "os_update_available": false,
  "os_bundle_url": "https://.../slide-announcer-2026.08.1.raucb",
  "os_bundle_sha256": "…",
  "os_auto_update_enabled": true
}
```
App-update fields keep the simple two-value `config`/env source of truth
from before. OS-update fields are read from
`slide_announcer_os_releases::where('channel', $slideAnnouncer->update_channel)->where('is_active', true)`
— which release a device is even offered depends on its `update_channel`
(stable/testing/developer), set from the website. `os_auto_update_enabled`
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
- **Publishing an OS release**: platform-admin-only (`Admin/*`, since a
  build is fleet-wide, not per-site) — `Admin/SlideAnnouncerReleasesController`
  with an upload form (bundle file → `Storage`, `sha256` computed
  server-side, `version`/`notes` fields) and an "activate" action that flips
  `is_active` on the chosen release (and off on the previous one). This is
  the entire "rollout" mechanism — see Tier 1 below for why no
  scheduling/cohort logic is needed on top of it.

### New routes summary
```
web.php:
  GET    /entities/{entity}/slide-announcers
  POST   /entities/{entity}/slide-announcers/pairing-codes
  DELETE /entities/{entity}/slide-announcers/{slideAnnouncer}

  admin/slide-announcers                       (cross-site fleet view)
  admin/slide-announcer-releases                (publish/activate RAUC bundles)

api.php (new file, registered in bootstrap/app.php):
  POST   /api/slide-announcers/pair       (public, throttled)
  GET    /api/slide-announcers/slides     (auth:sanctum, slide-announcer.auth)
  POST   /api/slide-announcers/heartbeat  (auth:sanctum, slide-announcer.auth)
```

### Open questions to resolve before implementation
1. Exact archive format for local-app releases (`.tar.gz` proposed) — to be
   finalized alongside Tier 2's updater design.
2. `slide_announcer_os_releases` is scoped by `channel`, not by individual
   entity — fine for now; if the fleet grows enough that per-site/cohort
   rollout (beyond three fixed channels) becomes worth the complexity, this
   table already has the right shape to grow an `entity_id` targeting
   column later.
3. No rollback story yet for an OS release that boots fine (passes the
   health check, gets `mark-good`) but has a bug discovered later — today
   the only fix is publishing a new release. Current call: acceptable until
   real-world usage shows otherwise; revisit once there's field experience
   to design against instead of guessing.

---

## Part 2 — Device-side architecture ([`slideannouncer`](slideannouncer/) submodule)

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
  is just the `slide_announcer_os_releases` table and the heartbeat
  response fields described in Part 1, backed by the same S3/R2 storage the
  slide files already use. This is the right tradeoff given the explicit
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
  tryboot. Only after the new slot boots and a health-check script passes
  does the unit call `rauc status mark-good`; if the check fails, tryboot's
  own fallback returns the device to the previous slot on the next boot
  automatically — the same auto-rollback property Mender would have given,
  achieved through RAUC's native mechanism instead.
- **Persistent state discipline**: anything that must survive an OS update
  (WiFi credentials, device pairing token, local-app releases, synced
  slides, sync-status file) lives on the persistent `/data` slot, never on
  rootfs — rootfs is replaced wholesale on each RAUC install. Systemd units
  reference `/data`-relative paths so they're unaffected by OS upgrades.
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

### First-boot / WiFi setup flow
- Backend drives NetworkManager via `nmcli` subprocess calls (not raw D-Bus
  bindings — more stable, testable), running as a dedicated non-root user
  authorized via a polkit rule.
- The setup UI itself (`local-app/frontend/setup`) is a single local web
  page regardless of how it's reached — it's just served by nginx on the
  device. That matters because it means the *input path* and the *display
  path* to that page are independent choices, not two different UIs to
  build.

**Primary path — on-device setup via an attached HID input (e.g. an RF
remote presenting as a keyboard/mouse combo).** Assumption: the remote is a
plug-and-play 2.4GHz RF dongle, not Bluetooth — it needs to work *before*
WiFi exists and ideally before any pairing has happened, so it can't depend
on a Bluetooth pairing step of its own. (Flag if the actual hardware is
Bluetooth-based — that reintroduces a chicken-and-egg problem this design
assumes away.)
- On boot, before deciding how to present setup, the backend checks for a
  usable HID input device (`/dev/input/event*` exposing keyboard + relative-
  pointer capabilities, e.g. via `evtest`/`libinput` introspection rather
  than assuming a specific device path).
- If found: **skip AP-mode/hotspot entirely.** `slide-announcer-kiosk.service`
  points Chromium straight at `http://localhost/setup` on the device's own
  display. The admin uses the remote to pick a network from a list
  (`nmcli device wifi list`) and type the password directly into the
  on-screen form — real key events into a real page, no on-screen keyboard
  needed. Once connected, the same display flips to the **pairing screen**
  to accept the numeric code, then to `kiosk` — identical state machine to
  before, just reached without ever involving a second device. No captive
  portal question exists on this path at all, since there was never a
  second network/device in the loop to trigger one.

**Fallback path — headless (no HID input detected).** Falls back to the
already-designed AP-mode flow: NetworkManager's built-in hotspot
(`nmcli device wifi hotspot`, avoids needing hostapd/dnsmasq) with a fixed
SSID → admin connects with a phone, browses to a printed IP → enters WiFi
credentials → backend connects via `nmcli`, tears down the hotspot →
pairing screen → `POST /api/slide-announcers/pair` → `kiosk` mode. True
captive-portal auto-popup on this path is still explicitly deferred (see
above in this doc's history) — the printed-IP flow is accepted as good
enough for now. **This path needs a dedicated design pass of its own** for
genuinely headless deployment (e.g. pre-configuring/mailing a device to a
site with no on-hand admin, or provisioning at scale before shipping) —
today it only covers "no keyboard, but someone's standing there with a
phone," not true zero-touch provisioning.
- Common to both paths: once WiFi credentials are stored, revisiting either
  path later requires an explicit admin action (see the local settings
  menu's "reset network"/unpair actions) — the device does **not**
  auto-fall-back into setup mode on a dropped connection (that would
  interrupt a live slideshow on a false-positive blip); a lost connection
  instead just shows the stale-cache indicator.

### Device identity & anti-clone protection
A device generates its own `device_uuid` (a random UUID) on first boot and
persists it — alongside the network interface's `mac_address` at that
moment — in `/data/identity.json`. `mac_address` is recorded because it's
useful for hardware inventory (matching a database row back to a physical
unit), but it is **not** the thing that makes a device unique from the
server's perspective — `device_uuid` (sent at pairing, stored on
`SlideAnnouncer`) is.

On every boot, the identity check compares the currently-detected MAC
against the one stored in `/data/identity.json`:
- **Match** (or first boot, nothing stored yet) → proceed normally.
- **Mismatch** → treat this as evidence the `/data` partition (or the whole
  SD card) was cloned onto different hardware, or moved from one Pi to
  another. Response: regenerate `device_uuid`, delete the stored pairing
  token and cached slides/settings, and boot into the pairing screen — the
  same wipe-and-reboot path revocation uses (see Heartbeat, above).

This is deliberate protection against SD-card cloning, not just device
identification: if a provisioned card is cloned onto a second Pi, the clone
detects a MAC mismatch on first boot and wipes/re-pairs itself independently
— so the two physical devices can never end up sharing one `device_uuid` (or
one still-valid pairing token) even though they briefly shared a disk image.
A full factory wipe/reset (see Kiosk display, below) triggers the same
identity regeneration deliberately, for the same reason.

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
  Raspberry Pi OS Bookworm+ already defaults, and current Chromium has
  better Wayland/Ozone support than X11 at this point. **Still needs a
  hands-on smoke test on the actual target Pi hardware** before locking it
  in for the fleet — no design change pending, just verification.
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

### Cross-tier update safety
No tier blocks another — local-app updates and slide sync both continue
through an OS update since `/data` is untouched by a rootfs swap. Avoid
scheduling an OS update and a local-app update in the same maintenance
window on one device, to keep failure attribution simple.

### Open questions / tradeoffs flagged
1. Hardware validation still pending (no design change riding on the
   outcome, just needs to happen before relying on this across a fleet you
   can't physically reach): Wayland/labwc on the actual target Pi model, and
   RAUC's tryboot integration (flash, install a bundle, force a bad health
   check, confirm fallback).
2. Symlink-swap (no A/B) for the local-app tier — revisit only if future
   local-app releases start needing local-state migrations a plain swap
   can't cleanly roll back.
3. Exact archive format for local-app releases (`.tar.gz` proposed) — to be
   finalized next.
4. True headless configuration (no attached keyboard/remote *and* no admin
   physically present with a phone) is not designed yet — the current
   fallback path assumes someone is on-site with a phone, even without a
   keyboard. Needs its own design pass if zero-touch/pre-shipped
   provisioning becomes a real requirement.
5. Confirm the RF remote hardware is a plug-and-play HID dongle, not
   Bluetooth — the on-device setup path assumes input works before any
   pairing (WiFi or Bluetooth) has happened.

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

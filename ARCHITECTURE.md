# Architecture

AnnouncementSlides is a web application for distributing announcement slides to
Seventh-day Adventist churches. A conference admin (or authorized contributor)
publishes slides that any church in the system can display; church leaders
manage slides scoped to their own congregation; and registered viewers can
submit slides for review. It replaces an error-prone email workflow where
admins emailed slides to pastors who forwarded them to A/V teams.

This document describes how the project is laid out and where each piece of the
implementation lives.

## Stack

- **Backend:** Laravel (`laravel/framework` ^13.8, PHP ^8.4) — see [composer.json](composer.json)
- **Frontend:** Vue 3 + [Inertia.js](https://inertiajs.com/) (no separate REST API; controllers return Inertia page responses) + Tailwind CSS, bundled with Vite — see [package.json](package.json)
- **Auth:** Laravel Breeze (email/password) plus optional Google OAuth via Laravel Socialite
- **i18n:** `vue-i18n` on the frontend ([resources/js/i18n.js](resources/js/i18n.js)); slide content is also language-*tagged* server-side (see below)
- **Database:** SQLite in dev, MySQL in prod
- **File storage:** Laravel Storage abstraction — local `public` disk in dev, S3-compatible (S3 / R2 / Spaces) in prod via `.env`. Files are never stored in the DB; only metadata rows in `slides`. See [config/filesystems.php](config/filesystems.php).

## Request lifecycle

1. Routes are declared in [routes/web.php](routes/web.php) (app) and [routes/auth.php](routes/auth.php) (Breeze auth). Console commands register in [routes/console.php](routes/console.php).
2. App bootstrapping, middleware, and aliases live in [bootstrap/app.php](bootstrap/app.php). The Inertia middleware [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php) shares the authenticated user, their entity memberships, and flash messages with every page.
3. Controllers in [app/Http/Controllers/](app/Http/Controllers/) return `Inertia::render('SomePage', [...])`, which maps to a Vue component in [resources/js/Pages/](resources/js/Pages/).
4. The root Blade shell is [resources/views/app.blade.php](resources/views/app.blade.php); the JS entrypoint is [resources/js/app.js](resources/js/app.js).

## Roles & access control

There are two independent permission layers:

**Global role** (`users.role`): `admin`, `contributor`, `viewer`, `banned`. Checked via helpers on [User](app/Models/User.php) (`isAdmin()`, `isContributor()`, `isBanned()`).
- Admin routes are gated by [EnsureAdmin](app/Http/Middleware/EnsureAdmin.php) and live under `/admin`.
- Banned users are blocked by [EnsureNotBanned](app/Http/Middleware/EnsureNotBanned.php) (aliased `not-banned` in [bootstrap/app.php](bootstrap/app.php)).

**Per-entity role** (`user_entities.role`, e.g. `admin`/member): a user can be a leader of one or more churches independent of their global role. Resolved via `User::entityRole()`, `adminEntities()`, and `memberEntityIds()`.

## Data model

Models live in [app/Models/](app/Models/); schema in [database/migrations/](database/migrations/).

- **[Slide](app/Models/Slide.php)** — the central model. Stores metadata (`title`, `notes`, file fields, `mime_type`, `thumbnail_path`), scheduling (`publish_at`, `expires_at`), a workflow `status` enum (`draft`/`pending`/`published`/`rejected`), `sort_order`, soft deletes, and FKs to `uploaded_by`/`reviewed_by` users, an optional `entity_id`, and an optional `language_id`. Image-quality fields (`image_width`, `image_height`, `validation_issues`, `validation_status`) and a `share_nearby` flag round it out.
- **[User](app/Models/User.php)** — global role, optional `google_id`/`avatar_url`, a many-to-many to `Entity` through `user_entities`, and per-user key/value settings via [UserSetting](app/Models/UserSetting.php) (`setting()` / `putSetting()`).
- **[Entity](app/Models/Entity.php)** — a church/organization that owns local slides and has members. Carries `latitude`/`longitude` for nearby sharing and a `deactivated` flag.
- **[AdventistEntity](app/Models/AdventistEntity.php)** — a *raw import* table of congregations scraped from adventistdirectory.org (richer fields: pastor, socials, conference code). This is the source data; `entity:sync` distills it into the working `Entity` table.
- **[Language](app/Models/Language.php)** — supported languages (`abbreviation`, `name`, `native_name`); seeds English + Spanish.
- **[UserInvitation](app/Models/UserInvitation.php)** — admin-issued invitations.

### Slide visibility & workflow (query-time, no cron)

[Slide](app/Models/Slide.php) defines query scopes that encode all the business rules:

- `current()` — published, publish date passed, not expired.
- `archived()` — published but expired.
- `upcoming()` — published but publish date still in the future.
- `pendingReview()` — `status = pending` (the submission queue).
- `unscoped()` / `entityScoped()` — global slides vs. one church's slides.
- `visibleToUser()` — global slides plus slides for entities the user belongs to.
- `shareNearby()` — slides a church has opted to share with neighbors.
- `language()` — current language *or* untagged (untagged slides show in every language).

Because expiry/publishing is computed at query time, no scheduled job is needed. `getDisplayStatusAttribute()` derives `scheduled`/`archived` labels for the UI.

### Nearby sharing

A church viewer can opt to also pull in slides from nearby congregations. [NearbyEntities::within()](app/Support/NearbyEntities.php) does a cheap SQL bounding-box pre-filter, then refines to an exact great-circle (haversine) radius in PHP so it stays DB-portable. The radius default is [config/slides.php](config/slides.php) (`SLIDES_NEARBY_RADIUS_MILES`, default 50), overridable per user via the `nearby_radius_miles` setting. Only the *borrowed* bucket is gated by the `share_nearby` flag.

## Controllers — who manages which slides

Each audience has its own controller and route group, keeping the scoping rules explicit.

| Area | Controller | Routes |
|---|---|---|
| Public viewing / download | [SlideController](app/Http/Controllers/SlideController.php) | `/`, `/archive`, `/slides/{slide}/download`, zip & pptx bulk download |
| Viewer submission | [SubmitSlideController](app/Http/Controllers/SubmitSlideController.php) | `/submit` |
| Contributor's own global slides | [MySlideController](app/Http/Controllers/MySlideController.php) | `/my-slides/*` |
| A church member's local slides | [LocalSlideController](app/Http/Controllers/LocalSlideController.php) | `/local-slides/*` (incl. reorder, archive, share-nearby) |
| A church leader managing an entity's slides | [EntitySlideController](app/Http/Controllers/EntitySlideController.php) | `/entity/{entity}/slides/*` |
| Entity subscriptions / search | [EntityController](app/Http/Controllers/EntityController.php) | `/entities/*` |
| Admin slide review & publishing | [Admin/SlideController](app/Http/Controllers/Admin/SlideController.php) | `/admin/slides/*` (approve, reject, archive, reorder) |
| Admin users & invitations | [Admin/UserController](app/Http/Controllers/Admin/UserController.php) | `/admin/users/*`, `/admin/invitations/*` |
| Admin dashboard | [Admin/DashboardController](app/Http/Controllers/Admin/DashboardController.php) | `/admin` |
| Admin oversight of entity slides | [Admin/EntityConsoleController](app/Http/Controllers/Admin/EntityConsoleController.php) | `/admin/entities/*` |
| Auth (Breeze + Google) | [app/Http/Controllers/Auth/](app/Http/Controllers/Auth/) | see [routes/auth.php](routes/auth.php) |

### Bulk downloads

[SlideController](app/Http/Controllers/SlideController.php) can package the currently-visible slides as a **ZIP** (`downloadZip`) or assemble them into a **PowerPoint** deck (`downloadPowerPoint`) using `phpoffice/phppresentation` — each image is centered, aspect-fit onto a black 16:9 slide.

## Uploads, validation & thumbnails

- **Chunked uploads:** large files (notably videos) upload in chunks via [ChunkedUploadController](app/Http/Controllers/ChunkedUploadController.php) (and an admin variant [Admin/ChunkedUploadController](app/Http/Controllers/Admin/ChunkedUploadController.php)). The frontend driver is [useChunkedUpload.js](resources/js/Composables/useChunkedUpload.js). Allowed MIME types are restricted to common image and video formats.
- **Image quality validation:** [ImageValidationService](app/Services/ImageValidationService.php) checks resolution (2–8.5 MP), file size (80 KB–5 MB), and 16:9 aspect ratio (±2%), recording `validation_issues`/`validation_status`. Mirrored client-side in [useImageValidation.js](resources/js/Composables/useImageValidation.js) and surfaced via [ValidationWarnings.vue](resources/js/Components/ValidationWarnings.vue). Low-quality global uploads from non-admins are hard-blocked.
- **Thumbnails:** generated asynchronously by the [GenerateThumbnail](app/Jobs/GenerateThumbnail.php) queued job. (`BuildZipArchive` is a stub for a future async zip path.)

## Frontend layout

Under [resources/js/](resources/js/):

- **[Pages/](resources/js/Pages/)** — one component per Inertia page, grouped by area (`Admin/`, `Slides/`, `MySlides/`, `LocalSlides/`, `Entity/`, `Submit/`, `Auth/`, `Profile/`).
- **[Layouts/](resources/js/Layouts/)** — `PublicLayout`, `AuthenticatedLayout`, `AdminLayout`, `GuestLayout`.
- **[Components/](resources/js/Components/)** — reusable UI (`SlideCard`, `SlideshowModal`, `UploadPanel`, `DropZone`, form controls, nav).
- **[Composables/](resources/js/Composables/)** — `useChunkedUpload`, `useImageValidation`.
- **[locales/](resources/js/locales/)** — `en.json`, `es.json` translation bundles; wired up in [i18n.js](resources/js/i18n.js).

## Console (Artisan) commands

In [app/Console/Commands/](app/Console/Commands/) — primarily for bootstrapping and data import:

- **Users:** `user:create`, `user:list`, `user:setrole`, `user:setpassword`
- **Churches/entities:** `church:load` (scrape a conference from adventistdirectory.org into `adventist_entities`), `church:list`, `church:detail`, `entity:sync` (distill into `entities`), `entity:assign` (grant/revoke a user's entity role)
- **Languages:** `language:add`, `language:list`

## Where to start when changing things

- **Add/alter a slide-visibility rule** → a scope on [Slide](app/Models/Slide.php), then the relevant controller query.
- **Change who can do what** → the role helpers on [User](app/Models/User.php) plus middleware in [app/Http/Middleware/](app/Http/Middleware/) and the route groups in [routes/web.php](routes/web.php).
- **Touch the upload pipeline** → [ChunkedUploadController](app/Http/Controllers/ChunkedUploadController.php), [ImageValidationService](app/Services/ImageValidationService.php), [GenerateThumbnail](app/Jobs/GenerateThumbnail.php).
- **UI** → the matching component in [resources/js/Pages/](resources/js/Pages/); shared props come from [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php).
- **Config knobs** → [config/slides.php](config/slides.php) and `.env` ([.env.example](.env.example)).

Tests live in [tests/](tests/) (PHPUnit; currently Breeze auth + profile coverage).

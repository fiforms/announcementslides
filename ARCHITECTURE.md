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

- **[Slide](app/Models/Slide.php)** — the central model. Stores metadata (`title`, `notes`, `text_description`, `link`), scheduling (`publish_at`, `expires_at`), a workflow `status` enum (`draft`/`pending`/`published`/`rejected`), `sort_order`, soft deletes, and FKs to `uploaded_by`/`reviewed_by` users, an optional `entity_id`, and an optional `language_id`. A `share_nearby` flag rounds it out. A slide's actual files live on its `media` relationship (see `SlideMedia` below) rather than on the `Slide` row itself; `Slide` exposes back-compat proxy accessors (`file_url`, `thumbnail_url`, `mime_type`, `original_filename`, `file_size`, `isImage()`/`isVideo()`) that resolve against `primaryMedia` (its `slide`-type file), so single-file-per-slide views keep working unchanged.
- **[SlideMedia](app/Models/SlideMedia.php)** — one row per file attached to a `Slide`. `media_type` is a plain string (`slide`, `slide-overlay`, `color-flyer`, `easy-print-flyer`, `social-media-image`, …) validated against `config('slides.media_types')` rather than a DB enum, so adding a new type is a config change, not a migration. Every slide has exactly one `slide`-type row (seeded at upload time); the rest are optional variants attached later from the Edit screens (`ManagesSlideMedia` trait, `.../{slide}/media` routes). Carries the same file/thumbnail/image-quality fields the old `Slide` row used to (`disk_path`, `mime_type`, `thumbnail_path`, `image_width`/`image_height`, `validation_issues`/`validation_status`), plus an `overlay_settings` JSON column that holds an overlay's validated **widget placements** (`{widgets: [...]}`; see *Overlay widgets* below). The rest of the editor's source stays inside the SVG. It also carries `variants`/`active_variant` for AI-upscaled images (see *AI upscaling* below): the row's own file columns always describe the *active* version, so nothing that reads a media row needs to know about upscaling.
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

- **Chunked uploads:** large files (notably videos) upload in chunks via [ChunkedUploadController](app/Http/Controllers/ChunkedUploadController.php). The frontend driver is [useChunkedUpload.js](resources/js/Composables/useChunkedUpload.js). `finalize()` seeds a new `Slide`'s primary `slide`-type `SlideMedia` row; allowed MIME types per media type come from `config('slides.media_types')` rather than a hardcoded list.
- **Attaching additional media to an existing slide** (overlay, flyers, social image, …) goes through a small `ManagesSlideMedia` trait ([app/Http/Controllers/Concerns/ManagesSlideMedia.php](app/Http/Controllers/Concerns/ManagesSlideMedia.php)) shared by every area's slide controller, backing each area's `.../{slide}/media` routes and the [MediaManager.vue](resources/js/Components/MediaManager.vue) component on the Edit pages.
- **SVG safety & the overlay editor.** Every uploaded SVG is sanitized in place ([SvgSanitizer](app/Services/SvgSanitizer.php): no scripts/handlers/foreignObject, only `#fragment` or PNG/JPEG/GIF data-URI hrefs), since files are served straight off the public disk. The Show Editor's Edit Slide modal and the admin slide editor (Admin/Slides/Edit) have an **Edit Overlay** tab ([OverlayEditor/](resources/js/Components/OverlayEditor/), pure model/compiler in [Composables/overlay/](resources/js/Composables/overlay/)) that builds a 1920×1080 overlay from text, rectangles, images, QR codes (`beautiful-qr-code`) and imported SVG, and saves it as the slide's single `slide-overlay` SVG (`local-slides.overlay.show`/`.save` and `admin.slides.overlay.show`/`.save` → `ManagesSlideMedia::showOverlayForSlide`/`saveOverlayForSlide`; other areas can wire it the same way). The editor's JSON source is embedded in that same file as `<metadata id="as-overlay-source">` ([OverlaySource](app/Services/OverlaySource.php)), minus heavy content that the body already holds (looked up by element id), together with a hash of the canonicalized body. If the body is later edited outside the editor, the hash no longer matches, and the file is offered as a locked base layer rather than re-opened as editable. The display side is unchanged: it still just renders one SVG. The QR design controls (`QrDesigner`/`QrPreview`) are also used on their own by the fully client-side **QR Code Creator** (`/qr-code`, [Pages/QrCode.vue](resources/js/Pages/QrCode.vue), linked from the user menu for any signed-in user), which downloads the code as SVG or PNG without contacting the server.
- **Overlay widgets.** These are admin-installed JavaScript packages (zip: `manifest.json` + ES module + icon) that run live above a slide's overlay image (clock, ICS calendar, …). The author guide is [resources/widgets/README.md](resources/widgets/README.md), with the sample widgets and standalone examples alongside it. How this app hosts them is in [resources/WIDGETS.md](resources/WIDGETS.md). `resources/widgets/` is meant to become its own repository, included as a submodule. The pieces:
  - **Installing.** [WidgetInstaller](app/Services/Widgets/WidgetInstaller.php) checks zip-slip, symlinks, sizes and extensions, and [WidgetManifest](app/Services/Widgets/WidgetManifest.php) validates the manifest. Install from **Admin → Widgets** ([Admin\WidgetController](app/Http/Controllers/Admin/WidgetController.php)) or with `widget:install <dir|zip>`. Bundles live under `widgets/{slug}/{version}/` on `config('widgets.disk')`. [WidgetAssetController](app/Http/Controllers/WidgetAssetController.php) serves only the files listed in the manifest.
  - **Placing.** In the overlay editor, a widget is an element of type `widget`, and its properties form is generated from the manifest's `parameters`. It is not compiled into the SVG. On save, [OverlayWidgets](app/Services/Widgets/OverlayWidgets.php) and [WidgetParams](app/Services/Widgets/WidgetParams.php) validate it into `overlay_settings`. This is the trust boundary for non-admin input.
  - **Playing.** Players get `slide.overlay_widgets` and mount each widget with [WidgetLayer.vue](resources/js/Components/Widgets/WidgetLayer.vue). The host does positioning and scaling; [widgetHost.js](resources/js/Composables/widgets/widgetHost.js) handles the mount/cleanup lifecycle and the `api` object. Thumbnails and the PPTX export draw each widget's icon or preview instead ([OverlayCompositor](app/Services/OverlayCompositor.php)).
  - **Fetching data.** There is no open proxy. A widget calls `api.fetch('<endpoint>')`, which goes to `widget-data.show` (by overlay and element reference, never a URL; access follows the slide's visibility) or `widget-data.preview` (editor only). [WidgetDataService](app/Services/Widgets/WidgetDataService.php) builds the URL from the manifest template, the saved params and any declared, type-checked runtime args (e.g. a forecast's lat/lon), checks `expect` (ICS is parsed server-side into events JSON with sabre/vobject), and keeps a shared cache with a last-good copy. The upstream request goes through [SafeHttpFetcher](app/Services/Widgets/SafeHttpFetcher.php), which applies the [UrlPolicy](app/Services/Widgets/UrlPolicy.php) rules:
    - https on port 443 only;
    - every resolved IP must be public, and the connection is pinned to the checked IP;
    - each redirect hop is checked again.

    Limits are in [config/widgets.php](config/widgets.php).
  - **Screen location.** Widgets get `api.location` from [WidgetLocation](app/Support/WidgetLocation.php): the screen's church coordinates, else the site default set on Admin → Widgets (`app_settings` key `widget_default_location`), else null. Web pages get it as the shared Inertia prop `widgetLocation`, resolved from `?entity_id=`, an `{entity}` route parameter or the session's current entity. Devices get it in the shows sync.
  - **On Slide Announcer devices.** The shows sync ([SlideAnnouncerSyncController](app/Http/Controllers/Api/SlideAnnouncerSyncController.php)) carries each slide's placements plus the bundles they need. The device mirrors those bundles locally (`slideannouncer/local-app/backend/widgets.py`, served from its own `/media/widgets/`) and proxies `api.fetch()` through its local backend to `/api/slide-announcers/widget-data/…` (`WidgetDataController::device`, token-authenticated, limited to slides the device syncs). It never fetches upstream URLs itself, and it serves the last good response while offline.
- **Browser resizing: AI upscaling (2x), downscaling (to 4K) and JPEG compression.** Slide images from 960×540 up to 1920×1080 can be doubled in resolution by an UpscalerJS/ESRGAN model running on the user's GPU, so nothing is processed on the server. Images *larger* than 4K (3840×2160, `config('slides.downscale')`) go the other way: [useImageResize.js](resources/js/Composables/useImageResize.js) draws them onto a hidden canvas at the fitted size (no AI) and encodes a JPEG the same way. A file over the 5 MB size limit whose dimensions are fine (e.g. a big PNG) is simply re-encoded as a JPEG at the same size (`compress`; kept only if smaller). These are all a `kind` of the same *resize* (`upscale` | `downscale` | `compress`, [ImageResize](app/Support/ImageResize.php) holds the target-size rules the server checks), stored identically: the result is the active version and the original is kept as a variant. Downscaling and compression are on by default and share one checkbox on the admin page (`downscale_oversized`). The upload forms (shared [UploadPanel](resources/js/Components/UploadPanel.vue), the admin slides page and the viewer Submit page) all use [useUploadResize](resources/js/Composables/useUploadResize.js) and [ResizeOption](resources/js/Components/ResizeOption.vue), so a new upload form only has to call them.
  - **Engine.** [useUpscaler.js](resources/js/Composables/useUpscaler.js) lazy-loads TensorFlow.js, `upscaler` and the chosen model (a separate ~1 MB chunk, never in the main bundle), upscales in tiles, flattens transparency onto white, and encodes a JPEG (quality from settings; re-encoded lower if over the 5 MB validation limit). It refuses the CPU backend. Model weights are self-hosted: `vite.config.js` copies each `@upscalerjs/*` model's 2x weights into `public/upscaler-models/<key>/` (git-ignored) on every dev/build, so a production deploy must run the Vite build (or copy that folder). Model keys live in `config('slides.upscale.models')` and the loader map in the composable.
  - **Settings.** Admin → AI Upscaler ([UpscalerSettingsController](app/Http/Controllers/Admin/UpscalerSettingsController.php), [Admin/UpscalerSettings.vue](resources/js/Pages/Admin/UpscalerSettings.vue), with a client-side "try a model" comparison panel): enabled, upscale-by-default, model, JPEG quality, tile size. They live in the key/value `app_settings` table ([AppSetting](app/Models/AppSetting.php), [UpscalerSettings](app/Support/UpscalerSettings.php)) and reach every page as the shared `upscaler` Inertia prop. The eligible size window is `config('slides.upscale')`.
  - **New uploads.** [UploadPanel](resources/js/Components/UploadPanel.vue) shows a per-file "Upscale 2×" toggle and upscales on submit (progress and cancel; a failure falls back to the original). [useChunkedUpload](resources/js/Composables/useChunkedUpload.js) uploads the upscaled JPEG and the original; `ChunkedUploadController::finalize` stores the resized file as the active version and the original as a variant.
  - **Existing images.** [MediaManager](resources/js/Components/MediaManager.vue) offers Upscale 2×, Downscale to 4K or Compress to JPEG (whichever the image's size calls for) / Undo / Redo / again on `slide`-type JPEG/PNG/WebP media, backed by `.../{slide}/media/{media}/resize` and `.../version` in each area's controller (`ManagesSlideMedia::resizeMediaForSlide` checks the result is a JPEG of exactly the size the `kind` calls for, `switchMediaVersionForSlide` flips versions). Undo/redo only swap which snapshot the row mirrors ([SlideMedia::switchToVariant](app/Models/SlideMedia.php)); both files and their thumbnails stay on disk until the media row is removed.
- **Image quality validation:** [ImageValidationService](app/Services/ImageValidationService.php) checks resolution (2–8.5 MP), file size (80 KB–5 MB), and 16:9 aspect ratio (±2%), recording `validation_issues`/`validation_status` on the `SlideMedia` row. Mirrored client-side in [useImageValidation.js](resources/js/Composables/useImageValidation.js) and surfaced via [ValidationWarnings.vue](resources/js/Components/ValidationWarnings.vue). Low-quality global uploads from non-admins are hard-blocked.
- **Thumbnails:** generated asynchronously by the [GenerateThumbnail](app/Jobs/GenerateThumbnail.php) queued job, which takes a `SlideMedia` (not a `Slide`). (`BuildZipArchive` is a stub for a future async zip path.)

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
- **Widgets:** `widget:install <dir|zip>…` (same checks as the admin upload; e.g. `php artisan widget:install resources/widgets/clock resources/widgets/calendar`)

## Where to start when changing things

- **Add/alter a slide-visibility rule** → a scope on [Slide](app/Models/Slide.php), then the relevant controller query.
- **Change who can do what** → the role helpers on [User](app/Models/User.php) plus middleware in [app/Http/Middleware/](app/Http/Middleware/) and the route groups in [routes/web.php](routes/web.php).
- **Touch the upload pipeline** → [ChunkedUploadController](app/Http/Controllers/ChunkedUploadController.php), [ImageValidationService](app/Services/ImageValidationService.php), [GenerateThumbnail](app/Jobs/GenerateThumbnail.php).
- **UI** → the matching component in [resources/js/Pages/](resources/js/Pages/); shared props come from [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php).
- **Browser resizing (AI upscale / downscale)** → [useUpscaler.js](resources/js/Composables/useUpscaler.js), [useImageResize.js](resources/js/Composables/useImageResize.js), [UpscalerSettings](app/Support/UpscalerSettings.php), [ImageResize](app/Support/ImageResize.php), `ManagesSlideMedia::resizeMediaForSlide`, [SlideMedia](app/Models/SlideMedia.php) variants (`original` + `resized`).
- **Config knobs** → [config/slides.php](config/slides.php), [config/widgets.php](config/widgets.php) and `.env` ([.env.example](.env.example)).
- **Widgets** → host contract in [widgetHost.js](resources/js/Composables/widgets/widgetHost.js), server side in [app/Services/Widgets/](app/Services/Widgets/), author guide in [resources/widgets/README.md](resources/widgets/README.md), app-specific notes in [resources/WIDGETS.md](resources/WIDGETS.md).

Tests live in [tests/](tests/) (PHPUnit; currently Breeze auth + profile coverage).

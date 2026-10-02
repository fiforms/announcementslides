# Overlay widgets in AnnouncementSlides

How AnnouncementSlides hosts the widgets described in [widgets/README.md](widgets/README.md). That README is the format and API reference for people writing a widget. This file is for people running or changing this app. [ARCHITECTURE.md](../ARCHITECTURE.md) has the wider picture.

`resources/widgets/` is a separate repository, included here as a git submodule. Widget format changes (manifest keys, `api` members) start there. This app implements the host side, so a change to the format needs a matching change to the files below.

## Installing widgets

A site admin installs a widget. Slide editors can then place it, but never upload code.

```bash
sudo -u www-data php artisan widget:install resources/widgets/clock resources/widgets/calendar resources/widgets/weather
```

Run artisan as `www-data` on the server. Files written by root make the widget's assets 404. The command takes directories or `.zip` files.

You can also zip a widget's folder and upload it at **Admin → Widgets** ([Admin\WidgetController](../app/Http/Controllers/Admin/WidgetController.php), [Widgets.vue](js/Pages/Admin/Widgets.vue)). Installing a package with an existing `id` upgrades it, and every slide using it gets the new version. Uninstalling removes its files and the widget row.

The same checks run for both routes ([WidgetInstaller](../app/Services/Widgets/WidgetInstaller.php), [WidgetManifest](../app/Services/Widgets/WidgetManifest.php)):

- Only these file types: `.js .mjs .json .css .png .webp .jpg .jpeg .gif .svg .woff .woff2 .txt .md`.
- At most 500 files and 20 MB, both zipped and unpacked (sizes are enforced while reading).
- No absolute or `..` paths, backslashes, NULs, symbolic links or odd file names. `__MACOSX/`, `.DS_Store` and `Thumbs.db` are ignored.
- A single wrapping folder is stripped.
- `manifest.json` is validated against the rules in the widget README (every problem is reported at once). `entry`, `icon` and `preview` must be files in the package.

Limits and the file-type table are in [config/widgets.php](../config/widgets.php).

## What admins can configure

On **Admin → Widgets**, per widget:

- **Enabled.** A disabled widget stays installed but can't be newly placed.
- **Settings.** The values for the manifest's `settings`, such as API keys. They are stored on the widget row and substituted into `{secret:name}` on the server. A blank field keeps the current value, and values are never sent back to the page.
- **Extra allowed prefixes.** For a `url` parameter that has an `allow` list, an admin can add more `https://` prefixes without editing the package. They are checked as part of parameter validation.

For the whole site:

- **Default location.** The fallback for `api.location`. See below.

## Where things live

| Concern | File |
|---|---|
| Bundle storage | `widgets/{slug}/{version}/` on `config('widgets.disk')` (`WIDGETS_DISK`, default `local`) |
| Serving bundle files | [WidgetAssetController](../app/Http/Controllers/WidgetAssetController.php), route `widgets.asset`. It serves only files listed in the installed manifest, same-origin, with the right MIME type. Public, because public slides' widgets run for guests. |
| Widget row | [Widget](../app/Models/Widget.php): `manifest`, `files`, `settings`, `extra_allow`, `enabled` |
| Parameter validation | [WidgetParams](../app/Services/Widgets/WidgetParams.php), the trust boundary for non-admin input |
| Placements | [OverlayWidgets](../app/Services/Widgets/OverlayWidgets.php) validates a slide overlay's widget elements into `slide_media.overlay_settings` |
| Browser host | [widgetHost.js](js/Composables/widgets/widgetHost.js) builds `api` and calls `mount`. [WidgetBox.vue](js/Components/Widgets/WidgetBox.vue) positions one placement, and [WidgetLayer.vue](js/Components/Widgets/WidgetLayer.vue) lays out all of a slide's. |
| Data endpoints | [WidgetDataController](../app/Http/Controllers/WidgetDataController.php), [WidgetDataService](../app/Services/Widgets/WidgetDataService.php) |
| Outbound requests | [SafeHttpFetcher](../app/Services/Widgets/SafeHttpFetcher.php), [UrlPolicy](../app/Services/Widgets/UrlPolicy.php) |
| Screen location | [WidgetLocation](../app/Support/WidgetLocation.php) |
| Thumbnails and exports | [OverlayCompositor](../app/Services/OverlayCompositor.php), [RevelationSnapshotBuilder](../app/Services/RevelationSnapshotBuilder.php) |

## How the browser host maps to the widget API

[widgetHost.js](js/Composables/widgets/widgetHost.js) is this app's implementation of the contract in the README.

- **Mounting.** `mountWidget` dynamically imports the entry module from `widgets.asset`, then calls `mount(el, { width, height, params, api })` with `width`/`height` taken from the saved placement and `params` frozen. [WidgetBox.vue](js/Components/Widgets/WidgetBox.vue) is keyed on placement, size and params, so any change remounts, and unmounting always runs the widget's cleanup. A returned function, or an object with `destroy()`, counts as cleanup. Then `el` is emptied.
- **`api.mode`.** `'live'` in players and lightboxes, `'editor'` in the overlay editor's preview.
- **`api.locale`.** The app's vue-i18n locale (`en`, `es`).
- **`api.storage`.** `localStorage` under `as-widget:{widgetId}:{placementId}:`.
- **`api.fetch`.** In live mode, `GET /widget-data/{slideMedia}/{element}/{endpoint}` with runtime args as `?args[name]=value`. The request carries a slide and an element, never a URL, and access follows the slide's visibility. In editor mode, `POST /widget-data/preview` with the editor's unsaved params. Failures reject with a `WidgetDataError` whose `.reason` is the server's error code.
- **`api.location`.** See below.

## Location

[WidgetLocation](../app/Support/WidgetLocation.php) gives `api.location`:

1. The screen's church, if it has coordinates (`source: 'entity'`, `name` like "Wilmington, NC"). On a Slide Announcer that is the device's church. On the web it is the church the page is showing, resolved from `?entity_id=`, an `{entity}` route parameter, or the session's current entity. Web pages get it as the shared Inertia prop `widgetLocation`, and devices get it in the shows sync.
2. Otherwise the site default from Admin → Widgets (`source: 'default'`), stored in `app_settings` as `widget_default_location`.
3. Otherwise `null`.

This is what lets one global slide show each church's own weather.

## The data server

[WidgetDataService](../app/Services/Widgets/WidgetDataService.php) implements `endpoints`:

- The URL is built from the manifest template, the **saved** parameter values and any declared runtime args. A request can't change the template.
- Every substituted value is percent-encoded. Runtime args are type-checked against the endpoint's `args`, and a missing or invalid arg is rejected as `invalid_args`.
- `ical` is parsed with sabre/vobject. Recurring events are expanded within `days` (default 60, from `ical_default_days`) and capped at `ical_max_events` (500).
- Responses are cached for `ttl` seconds (minimum 60, default 300) in a cache shared by all screens. When the upstream fails, the last good copy is served for up to 24 hours with `stale: true`.
- Rate limits count cache misses only: 60 per minute per caller, 30 per minute per upstream host, and 20 per minute for editor previews. Hitting them gives `rate_limited` or `upstream_busy`.

Every upstream request goes through [SafeHttpFetcher](../app/Services/Widgets/SafeHttpFetcher.php) and [UrlPolicy](../app/Services/Widgets/UrlPolicy.php):

- https on port 443 only;
- every resolved address must be public, and the connection is pinned to the address that was checked;
- each redirect hop is checked again, and must match the allowlist or stay on the same host;
- 5 second timeout, at most 3 redirects, and response size capped per `expect` (5 MB for `ical`, 2 MB for `json` and `text`).

Failures are recorded by reason in the cache, so a misbehaving widget can be diagnosed. Every number above is in [config/widgets.php](../config/widgets.php) under `fetch`.

The routes are throttled: `widget-data.show` 120/min, `widget-data.preview` 30/min.

## Thumbnails and exports

Static outputs can't run JavaScript, so [OverlayCompositor](../app/Services/OverlayCompositor.php) draws the widget's `preview` image, or its `icon`, into the placement's box. That covers slide thumbnails, the composited download and the PowerPoint export. The REVELation Snapshot export writes each widget placement as a `:widget:` block ([RevelationSnapshotBuilder](../app/Services/RevelationSnapshotBuilder.php)).

## Slide Announcer devices

The shows sync ([SlideAnnouncerSyncController](../app/Http/Controllers/Api/SlideAnnouncerSyncController.php)) carries each slide's placements plus the bundles they need.

- The device mirrors those bundles locally (`slideannouncer/local-app/backend/widgets.py`) and serves them from its own `/media/widgets/`, so widgets keep working through internet outages.
- `api.fetch()` goes through the device's backend to `/api/slide-announcers/widget-data/{slideMedia}/{element}/{endpoint}` (`WidgetDataController::device`, token-authenticated, limited to slides that device syncs). The device never fetches upstream URLs itself, and it serves the last good response, marked `stale: true`, while offline.
- Because of that, a widget can't rely on anything outside its package at runtime (CDN scripts, web fonts).

## Changing the widget format

When the manifest format or `api` changes:

1. Change and document it in the `resources/widgets` repository, with an example if it helps.
2. Update the matching host code here: [WidgetManifest](../app/Services/Widgets/WidgetManifest.php) and [WidgetParams](../app/Services/Widgets/WidgetParams.php) for validation, [WidgetDataService](../app/Services/Widgets/WidgetDataService.php) for endpoints, [widgetHost.js](js/Composables/widgets/widgetHost.js) for `api`, and the device's `widgets.py` if the device is affected.
3. Bump the submodule pointer in this repo in the same commit.
4. Reinstall the bundled widgets on the server (`widget:install`) if their manifests changed.

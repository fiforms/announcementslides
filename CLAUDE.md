# CLAUDE.md

This file gives Claude Code (and other AI assistants) the orientation it needs
to work in this repository.

## What this is

AnnouncementSlides — a Laravel + Vue/Inertia app for distributing announcement
slides to Seventh-day Adventist churches, with role-scoped management and a
submission/review workflow.

## Read this first

See **[ARCHITECTURE.md](ARCHITECTURE.md)** for the full project layout: the
stack, data model, slide visibility/workflow scopes, role system, controllers
by audience, the upload/validation/thumbnail pipeline, and where to start for
common changes. Consult it before making non-trivial edits.

## Conventions

- **No separate API** — controllers return Inertia page responses that map to
  Vue components in [resources/js/Pages/](resources/js/Pages/).
- **Slide rules live in scopes** on [app/Models/Slide.php](app/Models/Slide.php)
  (`current`, `archived`, `visibleToUser`, `language`, …); expiry/publishing is
  query-time, not cron-driven.
- **File storage is abstracted** through Laravel Storage (local in dev, S3-compatible
  in prod). Files are never stored in the DB — only metadata.
- **Two permission layers:** a global `users.role` and a per-entity role via
  `user_entities`. Don't conflate them.

## Common commands

```bash
composer dev      # serve + queue + logs + vite (concurrently)
composer test     # PHPUnit
npm run build     # build frontend assets
php artisan migrate
```

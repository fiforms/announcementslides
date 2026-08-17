# Installing / Deploying

This covers the parts of a production deployment that go beyond the local
dev setup in [README.md](README.md#installation): the queue worker and
`ffmpeg`, both required for slide thumbnails to actually generate.

## Requirements beyond README's list

- **`ffmpeg`** (with `ffprobe`) — used by [GenerateThumbnail](app/Jobs/GenerateThumbnail.php)
  to extract a thumbnail frame from video slides. Image slides don't need it
  (GD handles those), but any video slide will silently have no thumbnail
  without it.
  ```bash
  sudo apt install ffmpeg
  which ffmpeg ffprobe   # confirm both are on the PATH
  ```
  If `ffmpeg` isn't on the PATH the queue worker's process actually runs
  with (a common gap between an interactive shell's PATH and a
  systemd/supervisor service's PATH — check with
  `sudo -u <worker-user> which ffmpeg`), set an absolute path instead:
  ```ini
  # .env
  FFMPEG_BINARY=/usr/bin/ffmpeg
  ```
  (`config/slides.php`'s `ffmpeg_binary` key.)

## Queue worker (required)

`QUEUE_CONNECTION=database` by default, which means thumbnail generation
(and anything else queued) does **nothing** until something is actually
consuming the `jobs` table. A one-off `php artisan queue:work` run from a
terminal dies the moment that session ends — production needs a persistent,
auto-restarting worker.

Check whether one is already running:
```bash
ps aux | grep "queue:work"
```
And whether anything is backed up waiting for it:
```bash
php artisan tinker --execute="echo DB::table('jobs')->count() . ' pending, ' . DB::table('failed_jobs')->count() . ' failed' . PHP_EOL;"
```

### systemd unit

```ini
# /etc/systemd/system/announcementslides-queue.service
[Unit]
Description=AnnouncementSlides queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/web/your-domain
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Adjust `User=` to whichever user owns `storage/` and runs php-fpm for this
site, and `WorkingDirectory=` to the app's actual deploy path.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now announcementslides-queue
sudo systemctl status announcementslides-queue
```

`--max-time=3600` makes the worker exit cleanly every hour; `Restart=always`
relaunches it immediately. That's what actually picks up new code after a
deploy — a worker process keeps the *old* job classes loaded in memory for
as long as it runs, so without this it'd keep running stale code
indefinitely.

**After every deploy that touches a queued job's code** (anything in
`app/Jobs/`), restart the worker explicitly rather than waiting for the
hourly cycle:
```bash
sudo systemctl restart announcementslides-queue
```
Skipping this is exactly what caused a backlog of jobs to fail on
2026-08-17 — they'd been serialized against an old `GenerateThumbnail`
constructor signature and failed instantly once the worker (finally
started) tried to run them against the newly-deployed job class. That
particular symptom is a one-time migration hazard, not a recurring
one — restarting promptly after each deploy avoids the more general
version-skew case going forward.

### Diagnosing a stuck thumbnail

```bash
php artisan queue:failed                 # see why a job failed
tail -100 storage/logs/laravel.log | grep -A5 "Video thumbnail generation failed"
php artisan queue:flush                  # clear out stale/failed jobs once resolved
```

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\MediaStore;
use Symfony\Component\HttpFoundation\Response;

class SlideMedia extends Model
{
    protected $table = 'slide_media';

    protected $fillable = [
        'slide_id', 'show_id', 'media_type', 'filename', 'original_filename', 'disk_path', 'disk',
        'file_size', 'mime_type', 'thumbnail_path', 'poster_path', 'image_width', 'image_height',
        'validation_issues', 'validation_status', 'overlay_settings', 'sort_order',
        'variants', 'active_variant', 'purged_at',
    ];

    /** New rows start local; OffloadMedia moves them (mirrors the column default). */
    protected $attributes = [
        'disk' => MediaStore::LOCAL,
    ];

    protected function casts(): array
    {
        return [
            'file_size'         => 'integer',
            'image_width'       => 'integer',
            'image_height'      => 'integer',
            'sort_order'        => 'integer',
            'validation_issues' => 'array',
            'overlay_settings'  => 'array',
            'variants'          => 'array',
            'purged_at'         => 'datetime',
        ];
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(Slide::class);
    }

    /** Set (with slide_id null) for a show's frame media: show-base / show-overlay. */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    // ── Storage (see MediaStore and "Storage tiers" in ARCHITECTURE.md) ─────
    //
    // `disk_path` lives on `disk` (local `public`, or an offload disk such as
    // R2 once OffloadMedia has moved it). Thumbnails and video posters are
    // small and always stay on `public`.

    public function getFileUrlAttribute(): string
    {
        return app(MediaStore::class)->url($this->disk, $this->disk_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? app(MediaStore::class)->url(MediaStore::LOCAL, $this->thumbnail_path) : null;
    }

    /** A real path to the file for server-side processing (cached if offloaded), or null if missing. */
    public function localPath(): ?string
    {
        return app(MediaStore::class)->localPath($this->disk, $this->disk_path);
    }

    /** A local path to the video's full-size poster frame, if one is stored. */
    public function posterPath(): ?string
    {
        return app(MediaStore::class)->localPath(MediaStore::LOCAL, $this->poster_path);
    }

    public function fileExists(): bool
    {
        return app(MediaStore::class)->exists($this->disk, $this->disk_path);
    }

    public function isOffloaded(): bool
    {
        return !app(MediaStore::class)->isLocal($this->disk);
    }

    /** The file as an attachment download (a signed redirect if offloaded). */
    public function downloadResponse(string $name, array $headers = []): Response
    {
        return app(MediaStore::class)->download($this->disk, $this->disk_path, $name, $headers);
    }

    /** Whether this row's offloaded files were removed from the bucket (see media:purge-archived). */
    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * Removes this row's offloaded files (every version on a remote disk) from
     * the bucket, moving them to its trash, and marks the row purged. Local
     * files, thumbnails and posters stay. Returns false if there was nothing
     * offloaded to purge.
     */
    public function purgeFromBucket(): bool
    {
        if ($this->isPurged()) {
            return false;
        }

        $store  = app(MediaStore::class);
        $remote = array_filter($this->versions(), fn ($v) => !empty($v['disk_path']) && !$store->isLocal($v['disk'] ?? null));
        if (!$remote) {
            return false;
        }

        foreach ($remote as $version) {
            $store->delete($version['disk'], $version['disk_path']);
        }
        $this->forceFill(['purged_at' => now()])->save();

        return true;
    }

    /**
     * Brings purged files back: each one already in the bucket again (e.g.
     * uploaded from a backup mirror) or still in its trash. Clears the purged
     * mark only when every file is back. Returns whether the row is whole.
     */
    public function restorePurged(): bool
    {
        if (!$this->isPurged()) {
            return true;
        }

        $store = app(MediaStore::class);
        foreach ($this->versions() as $version) {
            $disk = $version['disk'] ?? MediaStore::LOCAL;
            if (empty($version['disk_path']) || $store->isLocal($disk)) {
                continue;
            }
            if (!$store->exists($disk, $version['disk_path']) && !$store->restoreFromTrash($disk, $version['disk_path'])) {
                return false;
            }
        }

        $this->forceFill(['purged_at' => null])->save();

        return true;
    }

    /** Deletes every file this row owns (all versions, thumbnails, posters) from wherever each lives. */
    public function deleteFiles(): void
    {
        foreach ($this->versions() as $version) {
            static::deleteVersionFiles($version);
        }
    }

    /** Deletes one version snapshot's files (see VERSION_FIELDS). */
    public static function deleteVersionFiles(array $version): void
    {
        $store = app(MediaStore::class);
        $store->delete($version['disk'] ?? MediaStore::LOCAL, $version['disk_path'] ?? null);
        $store->delete(MediaStore::LOCAL, array_filter([
            $version['thumbnail_path'] ?? null,
            $version['poster_path'] ?? null,
        ]));
    }

    /**
     * Every version of the file this row holds: the active one first, then
     * the stored variants (keyed by name), each as a VERSION_FIELDS snapshot.
     * Versions sharing a file are listed once.
     *
     * @return array<string, array>
     */
    public function versions(): array
    {
        $versions = ['current' => $this->currentVersion()];
        foreach ($this->variants ?? [] as $name => $v) {
            if (($v['disk_path'] ?? null) !== $this->disk_path) {
                $versions[$name] = $v + ['disk' => MediaStore::LOCAL];
            }
        }

        return $versions;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    // ── Browser resizing (AI upscale / downscale): original / resized versions ───────────────────────────

    /** The columns that describe a version of the file (see the variants migration). */
    public const VERSION_FIELDS = [
        'filename', 'original_filename', 'disk_path', 'disk', 'file_size', 'mime_type',
        'thumbnail_path', 'poster_path', 'image_width', 'image_height', 'validation_issues', 'validation_status',
    ];

    /** Whether the browser may be asked to resize (upscale/downscale) this file (not GIF/SVG/video/PDF). */
    public function canBeResized(): bool
    {
        return $this->media_type === 'slide'
            && in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function hasVariant(string $which): bool
    {
        return isset($this->variants[$which]);
    }

    /** The file columns as they are right now, as a version snapshot. */
    public function currentVersion(): array
    {
        return collect(self::VERSION_FIELDS)->mapWithKeys(fn ($f) => [$f => $this->getAttribute($f)])->all();
    }

    /**
     * Replaces the row's file columns with the other version's snapshot,
     * stashing the version being left (with its current thumbnail) so it can
     * be switched back to. Returns false if there is nothing to switch to.
     * Files are never deleted here — both versions stay on disk.
     */
    public function switchToVariant(string $which): bool
    {
        if (!$this->hasVariant($which) || $this->active_variant === $which) {
            return false;
        }

        $variants = $this->variants;
        if ($this->active_variant) {
            // Merged over the old snapshot so extras like kind/model survive.
            $variants[$this->active_variant] = $this->currentVersion() + $variants[$this->active_variant];
        }

        // Snapshots taken before storage tiers have no disk/poster: local, none.
        $this->forceFill(Arr::only($variants[$which], self::VERSION_FIELDS) + [
            'disk'           => MediaStore::LOCAL,
            'poster_path'    => null,
        ] + [
            'variants'       => $variants,
            'active_variant' => $which,
        ])->save();

        return true;
    }

    /**
     * Makes a browser-resized file (an AI upscale or a downscale, per $kind —
     * see ImageResize) the active version. $original is the snapshot of the
     * file as it was (kept for undo); $resized is the new file's columns
     * (VERSION_FIELDS); $model names the upscaler model, for upscales.
     */
    public function adoptResized(array $original, array $resized, string $kind, ?string $model = null): void
    {
        $this->forceFill($resized + [
            'variants'       => ['original' => $original, 'resized' => $resized + ['kind' => $kind, 'model' => $model]],
            'active_variant' => 'resized',
        ])->save();
    }

    /**
     * The filename to offer on download. A resized (upscaled, downscaled or
     * compressed) version is always re-encoded as JPEG but keeps the original
     * upload's name, so swap in a .jpg extension to match what the bytes are.
     */
    public function downloadName(): string
    {
        $name = $this->original_filename;
        if ($this->active_variant !== 'resized' || $this->mime_type !== 'image/jpeg') {
            return $name;
        }
        if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            return $name;
        }

        return pathinfo($name, PATHINFO_FILENAME) . '.jpg';
    }

    /**
     * The filename to offer on a slide's download: the slide's title, stripped
     * of special characters and trimmed to a sensible length, plus $suffix
     * (e.g. 'Background') and the real file's extension. Falls back to the
     * uploaded filename when the slide has no usable title.
     */
    public function titledDownloadName(string $suffix = ''): string
    {
        $fallback = $this->downloadName();
        $base = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $this->slide?->title);
        $base = trim($base);
        if (mb_strlen($base) > 60) {
            // Cut at the last whole word that fits (or hard-cut a single huge word).
            $cut  = mb_substr($base, 0, 60);
            $base = trim(mb_strlen($base) > 60 && $base[strlen($cut)] !== ' ' && str_contains($cut, ' ')
                ? mb_substr($cut, 0, mb_strrpos($cut, ' '))
                : $cut);
        }
        if ($base === '') {
            return $fallback;
        }

        $ext = strtolower(pathinfo($fallback, PATHINFO_EXTENSION));

        return $base . ($suffix !== '' ? " - {$suffix}" : '') . ($ext !== '' ? ".{$ext}" : '');
    }

    /** 'upscale' or 'downscale' for the stored resized version, if any. */
    public function resizedKind(): ?string
    {
        return $this->variants['resized']['kind'] ?? null;
    }

}

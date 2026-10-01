<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class SlideMedia extends Model
{
    protected $table = 'slide_media';

    protected $fillable = [
        'slide_id', 'media_type', 'filename', 'original_filename', 'disk_path',
        'file_size', 'mime_type', 'thumbnail_path', 'image_width', 'image_height',
        'validation_issues', 'validation_status', 'overlay_settings', 'sort_order',
        'variants', 'active_variant',
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
        ];
    }

    public function slide()
    {
        return $this->belongsTo(Slide::class);
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    // ── AI upscaling: original / upscaled versions ───────────────────────────

    /** The columns that describe a version of the file (see the variants migration). */
    public const VERSION_FIELDS = [
        'filename', 'original_filename', 'disk_path', 'file_size', 'mime_type',
        'thumbnail_path', 'image_width', 'image_height', 'validation_issues', 'validation_status',
    ];

    /** Whether the browser may be asked to upscale this file (not GIF/SVG/video/PDF). */
    public function canBeUpscaled(): bool
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
            // Merged over the old snapshot so extras like upscale_model survive.
            $variants[$this->active_variant] = $this->currentVersion() + $variants[$this->active_variant];
        }

        $this->forceFill(Arr::only($variants[$which], self::VERSION_FIELDS) + [
            'variants'       => $variants,
            'active_variant' => $which,
        ])->save();

        return true;
    }

    /**
     * Makes an upscaled file the active version. $original is the snapshot
     * of the file as it was (kept for undo); $upscaled is the new file's
     * columns (VERSION_FIELDS).
     */
    public function adoptUpscaled(array $original, array $upscaled, string $model): void
    {
        $this->forceFill($upscaled + [
            'variants'       => ['original' => $original, 'upscaled' => $upscaled + ['upscale_model' => $model]],
            'active_variant' => 'upscaled',
        ])->save();
    }

    /** Every file this row owns: both versions' files and thumbnails. */
    public function allFilePaths(): array
    {
        $paths = [$this->disk_path, $this->thumbnail_path];
        foreach ($this->variants ?? [] as $v) {
            $paths[] = $v['disk_path'] ?? null;
            $paths[] = $v['thumbnail_path'] ?? null;
        }

        return array_values(array_unique(array_filter($paths)));
    }
}

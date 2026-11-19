<?php

namespace App\Models;

use App\Support\LibraryFiles;
use App\Support\MediaStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the global slide library: a *background* (image or video,
 * stored once under a content-addressed key and shared by every slide built
 * from it), an *audio* file (a show's background music, same storage) or an overlay *template* (an editor-made overlay SVG that is
 * copied into each new slide). See LibraryFiles and "Slide library" in
 * ARCHITECTURE.md.
 */
class LibraryItem extends Model
{
    public const BACKGROUND = 'background';
    public const TEMPLATE = 'template';
    public const AUDIO = 'audio';

    protected $fillable = [
        'kind', 'title', 'sha256', 'filename', 'original_filename', 'disk', 'disk_path', 'mime_type',
        'file_size', 'thumbnail_path', 'poster_path', 'image_width', 'image_height', 'duration_seconds', 'overlay_settings', 'created_by',
    ];

    protected $attributes = ['disk' => MediaStore::LOCAL];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'image_width' => 'integer', 'image_height' => 'integer', 'duration_seconds' => 'integer', 'overlay_settings' => 'array'];
    }

    public function scopeBackgrounds(Builder $q): Builder
    {
        return $q->where('kind', self::BACKGROUND);
    }

    public function scopeAudio(Builder $q): Builder
    {
        return $q->where('kind', self::AUDIO);
    }

    public function scopeTemplates(Builder $q): Builder
    {
        return $q->where('kind', self::TEMPLATE);
    }

    /** Backgrounds whose thumbnail is built (or templates that have been saved once, or audio, which has no thumbnail): safe to offer. */
    public function scopeReady(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w
            ->where(fn ($b) => $b->where('kind', self::BACKGROUND)->whereNotNull('thumbnail_path'))
            ->orWhere(fn ($t) => $t->whereIn('kind', [self::TEMPLATE, self::AUDIO])->whereNotNull('disk_path')));
    }

    /** Shows using this audio as their background music. */
    public function audioShows(): BelongsToMany
    {
        return $this->belongsToMany(Show::class, 'show_audio');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isTemplate(): bool
    {
        return $this->kind === self::TEMPLATE;
    }

    public function isAudio(): bool
    {
        return $this->kind === self::AUDIO;
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->disk_path ? app(MediaStore::class)->url($this->disk, $this->disk_path) : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? app(MediaStore::class)->url(MediaStore::LOCAL, $this->thumbnail_path) : null;
    }

    /** What the picker shows: the thumbnail, or for a template the overlay SVG itself. */
    public function getPreviewUrlAttribute(): ?string
    {
        return $this->isTemplate() ? $this->file_url : $this->thumbnail_url;
    }

    public function localPath(): ?string
    {
        return $this->disk_path ? app(MediaStore::class)->localPath($this->disk, $this->disk_path) : null;
    }

    /** Frees this item's files that nothing else still uses (deferred; see LibraryFiles). */
    protected static function booted(): void
    {
        static::deleted(function (LibraryItem $item) {
            if ($item->isTemplate()) {
                // A template's SVG is its own file, never shared.
                $item->disk_path && app(MediaStore::class)->delete($item->disk, $item->disk_path);
            } else {
                LibraryFiles::pruneSoon();
            }
        });
    }
}

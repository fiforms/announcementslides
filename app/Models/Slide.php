<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Models\Entity;
use App\Models\User;

class Slide extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'notes', 'filename', 'original_filename', 'disk_path',
        'file_size', 'mime_type', 'thumbnail_path', 'publish_at', 'expires_at',
        'status', 'sort_order', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'entity_id', 'language_id',
        'image_width', 'image_height', 'validation_issues', 'validation_status',
    ];

    protected function casts(): array
    {
        return [
            'publish_at'  => 'datetime',
            'expires_at'  => 'datetime',
            'reviewed_at' => 'datetime',
            'file_size'   => 'integer',
            'sort_order'  => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'validation_issues' => 'array',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function language()
    {
        return $this->belongsTo(\App\Models\Language::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('publish_at')
            ->where('publish_at', '>', now());
    }

    public function scopeUnscoped(Builder $query): Builder
    {
        return $query->whereNull('entity_id');
    }

    public function scopeEntityScoped(Builder $query, int $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeVisibleToUser(Builder $query, ?User $user): Builder
    {
        if ($user) {
            $entityIds = $user->memberEntityIds();
            return $query->where(fn ($q) => $q->whereNull('entity_id')->orWhereIn('entity_id', $entityIds));
        }
        return $query->whereNull('entity_id');
    }

    public function scopeLanguage(Builder $query, ?int $languageId): Builder
    {
        if ($languageId === null) {
            return $query;
        }
        return $query->where(fn ($q) => $q->whereNull('language_id')->orWhere('language_id', $languageId));
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

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
}

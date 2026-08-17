<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SlideMedia extends Model
{
    protected $table = 'slide_media';

    protected $fillable = [
        'slide_id', 'media_type', 'filename', 'original_filename', 'disk_path',
        'file_size', 'mime_type', 'thumbnail_path', 'image_width', 'image_height',
        'validation_issues', 'validation_status', 'overlay_settings', 'sort_order',
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
}

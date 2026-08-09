<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerOsRelease extends Model
{
    protected $fillable = [
        'version',
        'channel',
        'bundle_disk_path',
        'sha256',
        'is_active',
        'notes',
        'released_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveOnChannel($query, string $channel)
    {
        return $query->where('channel', $channel)->where('is_active', true);
    }

    public function bundleUrl(): string
    {
        return Storage::disk('public')->url($this->bundle_disk_path);
    }

    /**
     * Deactivates any other release on this release's channel, then
     * activates this one. This is the entire "rollout" mechanism — see
     * SLIDE_ANNOUNCER.md, "New data model."
     */
    public function activate(): void
    {
        static::where('channel', $this->channel)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->update(['is_active' => true, 'released_at' => $this->released_at ?? now()]);
    }
}

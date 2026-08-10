<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerRelease extends Model
{
    const KINDS = ['os', 'app'];

    protected $fillable = [
        'kind',
        'version',
        'channel',
        'disk_path',
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

    public function scopeActiveOnChannel($query, string $kind, string $channel)
    {
        return $query->where('kind', $kind)->where('channel', $channel)->where('is_active', true);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }

    /**
     * Deactivates any other release of the same kind+channel, then
     * activates this one — the entire "rollout" mechanism for both OS
     * bundles and local-app archives. See SLIDE_ANNOUNCER.md, "New data
     * model."
     */
    public function activate(): void
    {
        static::where('kind', $this->kind)
            ->where('channel', $this->channel)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->update(['is_active' => true, 'released_at' => $this->released_at ?? now()]);
    }
}

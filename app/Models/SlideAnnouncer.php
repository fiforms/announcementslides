<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class SlideAnnouncer extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'entity_id',
        'name',
        'mac_address',
        'device_uuid',
        'app_version',
        'os_version',
        'update_channel',
        'auto_update_enabled',
        'settings',
        'last_seen_at',
        'last_ip',
        'last_cpu_temp_c',
        'paired_at',
        'paired_by',
        'revoked_at',
    ];

    protected $casts = [
        'auto_update_enabled' => 'boolean',
        'settings' => 'array',
        'last_seen_at' => 'datetime',
        'paired_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function pairedBy()
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    public function heartbeats()
    {
        return $this->hasMany(SlideAnnouncerHeartbeat::class);
    }

    public function isOnline(): bool
    {
        $thresholdMinutes = config('slide_announcer.online_threshold_minutes', 3);

        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes($thresholdMinutes));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}

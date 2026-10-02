<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class SlideAnnouncer extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'entity_id',
        'language_id',
        'language_revision',
        'name',
        'mac_address',
        'device_uuid',
        'app_version',
        'os_version',
        'architecture',
        'update_channel',
        'auto_update_enabled',
        'srt_sink_enabled',
        'srt_sink_passphrase',
        'srt_sink_config',
        'srt_sink_config_revision',
        'srt_sink_config_applied_revision',
        'hostname',
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
        'language_revision' => 'integer',
        'srt_sink_enabled' => 'boolean',
        'srt_sink_config' => 'array',
        'srt_sink_config_revision' => 'integer',
        'srt_sink_config_applied_revision' => 'integer',
        'settings' => 'array',
        'last_seen_at' => 'datetime',
        'paired_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * The one place a device's language changes, so the revision always
     * moves with it. No-op (no revision bump) if it's already that language.
     */
    public function changeLanguage(?int $languageId): void
    {
        if ($languageId === $this->language_id) {
            return;
        }

        $this->update([
            'language_id' => $languageId,
            'language_revision' => $this->language_revision + 1,
        ]);
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

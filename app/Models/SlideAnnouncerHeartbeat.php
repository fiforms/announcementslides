<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlideAnnouncerHeartbeat extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'slide_announcer_id',
        'app_version',
        'os_version',
        'ip_address',
        'cpu_temp_c',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function slideAnnouncer()
    {
        return $this->belongsTo(SlideAnnouncer::class);
    }
}

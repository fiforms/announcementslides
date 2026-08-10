<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlideAnnouncerReleaseChannel extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'slide_announcer_release_id',
        'channel',
        'tagged_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function release()
    {
        return $this->belongsTo(SlideAnnouncerRelease::class, 'slide_announcer_release_id');
    }

    public function taggedBy()
    {
        return $this->belongsTo(User::class, 'tagged_by');
    }
}

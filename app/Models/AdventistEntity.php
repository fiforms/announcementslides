<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdventistEntity extends Model
{
    protected $fillable = [
        'conference_code',
        'om_entity_id',
        'org_mast_id',
        'entity_type',
        'name',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'latitude',
        'longitude',
        'phone',
        'website',
        'pastor',
        'language',
        'facebook',
        'instagram',
        'twitter',
        'youtube',
        'extra',
    ];

    protected $casts = [
        'extra' => 'array',
    ];
}

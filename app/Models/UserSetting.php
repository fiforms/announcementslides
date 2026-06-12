<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'setting_tag',
        'setting_value',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single decrementing counter per automatic sort zone ('global', 'nearby').
 * See App\Support\SortZones and Slide::assignFanoutSortOrderIfNeeded().
 */
class SortCounter extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'value'];
}

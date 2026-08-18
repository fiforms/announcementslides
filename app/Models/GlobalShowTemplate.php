<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identity for a globally-distributed one-off show (e.g. a special
 * sound-on promo video every site gets its own copy of). Each entity's copy
 * is a separate Show row (shows.global_template_id), so leaders can locally
 * hide/reorder/rename theirs independently; this row exists purely so all
 * those per-entity copies can be found and swept together once the
 * underlying content expires (see Console\Commands\PruneEmptyShows).
 */
class GlobalShowTemplate extends Model
{
    protected $fillable = ['name', 'created_by'];

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'global_template_id');
    }
}

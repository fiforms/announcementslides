<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The widget layer pinned above a whole show (see the show_overlays
 * migration and App\Services\Widgets\OverlayWidgets). Widgets only — there is
 * no SVG; show-level widgets always draw above a slide's own.
 */
class ShowOverlay extends Model
{
    protected $fillable = ['show_id', 'source', 'overlay_settings'];

    protected function casts(): array
    {
        return ['source' => 'array', 'overlay_settings' => 'array'];
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}

<?php

namespace App\Models;

use App\Support\LiveChannelSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A live stream in the global, admin-curated catalog that a show can use as
 * its frame background (Show::frame_channel_id). `source` holds only the
 * provider's validated ids; see LiveChannelSource.
 */
class LiveChannel extends Model
{
    protected $fillable = ['name', 'provider', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['source' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'frame_channel_id');
    }

    public function embedUrl(bool $muted): ?string
    {
        return LiveChannelSource::embedUrl($this->provider, $this->source ?? [], $muted);
    }

    /**
     * What a player needs: {kind: 'iframe'|'hls', url, muted}, or null if the
     * stored source no longer validates. An iframe's mute is already in its
     * URL (where the provider supports it); an HLS player applies `muted`.
     */
    public function forPlayer(bool $muted): ?array
    {
        $muted = $muted && $this->supportsMute();
        $url = $this->embedUrl($muted);

        return $url ? ['kind' => LiveChannelSource::kind($this->provider), 'url' => $url, 'muted' => $muted] : null;
    }

    public function supportsMute(): bool
    {
        return LiveChannelSource::supportsMute($this->provider);
    }
}

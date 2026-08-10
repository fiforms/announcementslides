<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerRelease extends Model
{
    const KINDS = ['os', 'app'];
    const CHANNELS = ['stable', 'testing', 'developer'];

    protected $fillable = [
        'kind',
        'version',
        'architecture',
        'disk_path',
        'sha256',
        'notes',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function channels()
    {
        return $this->hasMany(SlideAnnouncerReleaseChannel::class);
    }

    public function scopeOfKindAndArchitecture($query, string $kind, ?string $architecture)
    {
        return $query->where('kind', $kind)->where('architecture', $architecture);
    }

    public function scopeCurrentOnChannel($query, string $kind, ?string $architecture, string $channel)
    {
        return $query->ofKindAndArchitecture($kind, $architecture)
            ->whereHas('channels', fn ($q) => $q->where('channel', $channel));
    }

    public function currentChannelNames(): array
    {
        return $this->channels->pluck('channel')->all();
    }

    public function isArchived(): bool
    {
        return $this->channels->isEmpty();
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->disk_path);
    }

    /**
     * Tags this release as current on $channel. At most one release can
     * hold a given (kind, architecture, channel) tag at a time, so any
     * sibling release (same kind+architecture) currently holding it loses
     * the tag first — a "move," not a toggle. See SLIDE_ANNOUNCER.md's
     * "New data model" for why this replaced a per-row is_active flag.
     */
    public function tagChannel(string $channel, ?int $userId = null): void
    {
        SlideAnnouncerReleaseChannel::where('channel', $channel)
            ->whereHas('release', fn ($q) => $q->ofKindAndArchitecture($this->kind, $this->architecture))
            ->delete();

        $this->channels()->firstOrCreate(['channel' => $channel], ['tagged_by' => $userId]);
    }

    /**
     * Removes this release's tag for $channel, if any. A channel left
     * with no tagged release simply has no current build for that
     * (kind, architecture) — untagging every channel is what makes a
     * release "archived" (see isArchived()), not a separate flag.
     */
    public function untagChannel(string $channel): void
    {
        $this->channels()->where('channel', $channel)->delete();
    }
}

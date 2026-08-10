<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SlideAnnouncerRelease extends Model
{
    const KINDS = ['os', 'app'];
    const CHANNELS = ['stable', 'testing', 'developer'];

    // 'full' is a complete OTA image (os, .raucb) or app archive (app,
    // .tar.gz); 'hotfix' (os only, .raucb) requires required_base_version
    // and only applies on top of that exact version; 'disk_image' (os
    // only, .img.xz) is a flashable disk image for re-imaging an SD card,
    // never an OTA candidate.
    const RELEASE_TYPES = ['full', 'hotfix', 'disk_image'];

    protected $fillable = [
        'kind',
        'version',
        'architecture',
        'release_type',
        'required_base_version',
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

    /**
     * Picks the release a device should be offered: an exact-match hotfix
     * (its required_base_version equals the device's current version) if
     * one is tagged on this channel, else the full release tagged there.
     * disk_image releases are never returned — they're not OTA candidates.
     * Multi-version upgrades resolve one hop per heartbeat, not in one
     * response (see SLIDE_ANNOUNCER.md).
     */
    public static function resolveForDevice(string $kind, ?string $architecture, string $channel, ?string $currentVersion): ?self
    {
        if ($currentVersion !== null) {
            $hotfix = static::currentOnChannel($kind, $architecture, $channel)
                ->where('release_type', 'hotfix')
                ->where('required_base_version', $currentVersion)
                ->first();

            if ($hotfix) {
                return $hotfix;
            }
        }

        return static::currentOnChannel($kind, $architecture, $channel)
            ->where('release_type', 'full')
            ->first();
    }

    /**
     * Parses a filename like "slideannouncer-1.2.0.raucb" or
     * "slideannouncer-1.2.1.hotfix.from.1.2.0.raucb" into version/
     * release_type/required_base_version. Returns null for anything else
     * — the admin GUI falls back to manual entry when this doesn't match,
     * it never blocks the upload. kind/release_type still stay explicit
     * admin selections either way — this only automates version/hotfix
     * detection, not which of the three extensions is expected.
     */
    public static function parseFilename(string $filename): ?array
    {
        $pattern = '/^slideannouncer-(?<version>\d+\.\d+\.\d+)'
            . '(?:\.hotfix\.from\.(?<base>\d+\.\d+\.\d+))?'
            . '\.(?:raucb|tar\.gz|img\.xz)$/i';

        if (! preg_match($pattern, $filename, $matches)) {
            return null;
        }

        $base = $matches['base'] ?? '';

        return [
            'version' => $matches['version'],
            'release_type' => $base !== '' ? 'hotfix' : 'full',
            'required_base_version' => $base !== '' ? $base : null,
        ];
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
     * Tags this release as current on $channel. Releases occupy
     * independent "slots" per (kind, architecture, channel): one slot for
     * the full release, one slot for the disk image, and one slot per
     * distinct required_base_version among hotfixes — so a full release
     * and one or more hotfixes (targeting different base versions) can
     * all be tagged on the same channel at once. Tagging evicts only the
     * sibling occupying the same slot — a "move," not a toggle. See
     * SLIDE_ANNOUNCER.md's "New data model" for why this replaced a
     * per-row is_active flag.
     */
    public function tagChannel(string $channel, ?int $userId = null): void
    {
        SlideAnnouncerReleaseChannel::where('channel', $channel)
            ->whereHas('release', function ($q) {
                $q->ofKindAndArchitecture($this->kind, $this->architecture)
                    ->where('release_type', $this->release_type);

                if ($this->release_type === 'hotfix') {
                    $q->where('required_base_version', $this->required_base_version);
                }
            })
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

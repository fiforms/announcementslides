<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One user's copy of a notification (see App\Services\Notifier). Each
 * recipient gets their own row, so reading, clearing (archiving) and
 * restoring are per user.
 *
 * `type` is a short key (e.g. `slide_approved`) the frontend turns into text
 * via `notifications.types.<type>` in the locale files; `data` holds
 * `params` (the values that text interpolates), an optional `url` and an
 * optional `slide_id` whose thumbnail is looked up when shown (it usually
 * doesn't exist yet when the notification is created).
 */
class Notification extends DatabaseNotification
{
    protected $casts = [
        'data'        => 'array',
        'read_at'     => 'datetime',
        'archived_at' => 'datetime',
        'emailed_at'  => 'datetime',
    ];

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    public function scopeInbox(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** Clear it from the inbox; clearing also counts as having read it. */
    public function archive(): void
    {
        $now = now();
        $this->forceFill([
            'archived_at' => $now,
            'read_at'     => $this->read_at ?? $now,
        ])->save();
    }

    public function restore(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Client shapes for a page of notifications, with slide thumbnails
     * resolved in one query.
     *
     * @param  iterable<self>  $notifications
     */
    public static function toClientMany(iterable $notifications): Collection
    {
        $notifications = collect($notifications);
        $slideIds = $notifications->pluck('data.slide_id')->filter()->unique();

        $thumbs = $slideIds->isEmpty() ? collect() : Slide::with('primaryMedia')
            ->whereIn('id', $slideIds)->get()
            ->mapWithKeys(fn (Slide $s) => [$s->id => $s->thumbnail_url]);

        return $notifications->map(fn (self $n) => $n->toClient($thumbs[$n->data['slide_id'] ?? 0] ?? null));
    }

    /** The shape the inbox, archive and bell render. */
    public function toClient(?string $thumbnail = null): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'category'   => $this->category,
            'params'     => $this->data['params'] ?? [],
            'url'        => $this->data['url'] ?? null,
            'thumbnail'  => $thumbnail,
            'read'       => $this->read_at !== null,
            'archived'   => $this->archived_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services;

use App\Jobs\SendNotificationEmail;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationPreferences;
use App\Support\NotificationSettings;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single way notifications are created.
 *
 * One event → one row per recipient, so every user reads and clears their
 * own copy. Callers decide *who* is affected and *what* to say; this applies
 * each recipient's preferences (NotificationPreferences), skips banned users
 * and anyone outside an admin-only category, and writes the rows with a
 * chunked bulk insert — a fan-out to hundreds of users is a handful of
 * queries. Email never happens here: immediate emails are queued per
 * recipient and the daily digest is a scheduled command, so nothing a
 * request does waits on a mail server.
 *
 * Fan-outs that can reach many users (global/nearby slides, promos) call
 * this from a queued job, not from the request.
 */
class Notifier
{
    private const CHUNK = 500;

    /**
     * @param  string  $category  a key of config('notifications.categories')
     * @param  string  $type  short key the frontend renders (notifications.types.<type>)
     * @param  iterable<int|User>  $recipients  users or user ids (duplicates are ignored)
     * @param  array|Closure(User): ?array  $data  ['params' => [...], 'url' => ?, 'thumbnail' => ?],
     *                                              or a closure building it per user (null skips that user)
     * @return int rows created
     */
    public static function send(string $category, string $type, iterable $recipients, array|Closure $data): int
    {
        if (! NotificationPreferences::exists($category)) {
            throw new InvalidArgumentException("Unknown notification category [$category].");
        }

        $ids = collect($recipients)
            ->map(fn ($r) => $r instanceof User ? $r->getKey() : (int) $r)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $adminOnly = NotificationPreferences::audience($category) === 'admin';
        $emailEnabled = NotificationSettings::emailEnabled();
        $morph = (new User)->getMorphClass();
        $created = 0;
        $immediate = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $users = User::query()
                ->whereIn('id', $chunk->all())
                ->where('role', '!=', 'banned')
                ->when($adminOnly, fn ($q) => $q->where('role', 'admin'))
                ->get();

            $prefs = NotificationPreferences::bulk($users->modelKeys(), $category);
            $now = now();
            $rows = [];

            foreach ($users as $user) {
                $pref = $prefs[$user->id];
                $email = $emailEnabled ? $pref['email'] : 'none';

                // Muted in the inbox and not emailed: nothing to keep.
                if (! $pref['inbox'] && $email === 'none') {
                    continue;
                }

                $payload = $data instanceof Closure ? $data($user) : $data;
                if ($payload === null) {
                    continue;
                }

                $id = (string) Str::uuid();
                $rows[] = [
                    'id'              => $id,
                    'type'            => $type,
                    'notifiable_type' => $morph,
                    'notifiable_id'   => $user->id,
                    'data'            => json_encode($payload),
                    'category'        => $category,
                    'email_mode'      => $email,
                    // Muted in the inbox but still emailed: file it straight
                    // into the archive so the digest can find it.
                    'archived_at'     => $pref['inbox'] ? null : $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];

                if ($email === 'immediate') {
                    $immediate[] = $id;
                }
            }

            if ($rows) {
                Notification::insert($rows);
                $created += count($rows);
            }
        }

        static::queueImmediateEmails($immediate);

        return $created;
    }

    /** @param array<string> $notificationIds */
    protected static function queueImmediateEmails(array $notificationIds): void
    {
        foreach ($notificationIds as $id) {
            SendNotificationEmail::dispatch($id);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Support\NotificationSettings;
use Illuminate\Console\Command;

/**
 * Keeps the notifications table bounded: deletes cleared (archived)
 * notifications older than the retention period, and archives inbox items
 * nobody cleared once they pass the inbox age limit. Both values are set on
 * Admin → Notifications (defaults in config/notifications.php).
 */
class NotificationsPrune extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete archived notifications past retention and archive stale inbox items';

    private const BATCH = 1000;

    public function handle(): int
    {
        $archived = 0;
        $inboxDays = NotificationSettings::get('inbox_max_days');

        if ($inboxDays > 0) {
            do {
                $ids = Notification::inbox()->where('created_at', '<', now()->subDays($inboxDays))
                    ->limit(self::BATCH)->pluck('id');
                $archived += $ids->isEmpty() ? 0 : Notification::whereKey($ids->all())
                    ->update(['archived_at' => now()]);
            } while ($ids->count() === self::BATCH);
        }

        $deleted = 0;
        $cutoff = now()->subDays(max(1, NotificationSettings::get('retention_days')));

        do {
            $ids = Notification::archived()->where('archived_at', '<', $cutoff)
                ->limit(self::BATCH)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : Notification::whereKey($ids->all())->delete();
        } while ($ids->count() === self::BATCH);

        $this->info("Archived $archived stale inbox notification(s); deleted $deleted past retention.");

        return self::SUCCESS;
    }
}

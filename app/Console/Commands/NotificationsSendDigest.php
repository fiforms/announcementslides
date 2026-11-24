<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationDigest;
use App\Models\Notification;
use App\Support\NotificationSettings;
use Illuminate\Console\Command;

/**
 * Queues one digest email per user who has "Daily digest" notifications
 * waiting. Scheduled daily at the time set on Admin → Notifications.
 */
class NotificationsSendDigest extends Command
{
    protected $signature = 'notifications:send-digest';

    protected $description = 'Queue the daily notification digest emails';

    public function handle(): int
    {
        if (! NotificationSettings::emailEnabled()) {
            $this->info('Notification email is turned off (Admin → Notifications).');

            return self::SUCCESS;
        }

        $userIds = Notification::query()
            ->where('email_mode', 'daily')
            ->whereNull('emailed_at')
            ->distinct()
            ->pluck('notifiable_id');

        foreach ($userIds as $id) {
            SendNotificationDigest::dispatch((int) $id);
        }

        $this->info("Queued {$userIds->count()} digest(s).");

        return self::SUCCESS;
    }
}

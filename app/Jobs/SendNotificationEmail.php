<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationSettings;
use App\Support\NotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one "Right away" notification. One job per recipient, so a slow
 * or failing mail server retries per message and never holds up the
 * request that caused the notification (see App\Services\Notifier).
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $notificationId) {}

    public function handle(): void
    {
        $n = Notification::find($this->notificationId);

        if (! $n || $n->emailed_at || $n->email_mode !== 'immediate' || ! NotificationSettings::emailEnabled()) {
            return;
        }

        $user = $n->notifiable;
        if (! $user instanceof User || $user->isBanned() || ! $user->email) {
            return;
        }

        Mail::to($user)->send((new NotificationMail($n, $user))->locale(NotificationText::localeFor($user)));

        $n->forceFill(['emailed_at' => now()])->save();
    }
}

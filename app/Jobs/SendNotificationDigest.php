<?php

namespace App\Jobs;

use App\Mail\NotificationDigestMail;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationPreferences;
use App\Support\NotificationSettings;
use App\Support\NotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * One user's daily digest: every "Daily digest" notification not yet
 * emailed. Ones the user already read in the app are left out of the email
 * (they've seen them) but still marked as handled, as are ones whose
 * category they've since switched email off for. Queued per user by
 * notifications:send-digest.
 */
class SendNotificationDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || ! NotificationSettings::emailEnabled()) {
            return;
        }

        $pending = Notification::forUser($user)
            ->where('email_mode', 'daily')
            ->whereNull('emailed_at')
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $current = NotificationPreferences::forUser($user);
        $toSend = $pending->filter(fn (Notification $n) => $n->read_at === null
            && ($current[$n->category]['email'] ?? 'none') !== 'none');

        if ($toSend->isNotEmpty() && ! $user->isBanned() && $user->email) {
            Mail::to($user)->send(
                (new NotificationDigestMail($toSend->values(), $user))->locale(NotificationText::localeFor($user))
            );
        }

        Notification::whereKey($pending->modelKeys())->update(['emailed_at' => now()]);
    }
}

<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationText;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/** The daily summary: a user's unread "Daily digest" notifications, grouped by category. */
class NotificationDigestMail extends Mailable
{
    use Queueable;

    /** Most items listed in one email; the rest are summed up with a link. */
    public const MAX_ITEMS = 50;

    /** @param Collection<int, Notification> $notifications */
    public function __construct(public Collection $notifications, public User $user) {}

    public function envelope(): Envelope
    {
        $count = $this->notifications->count();

        return new Envelope(subject: trans_choice('notifications.digest_subject', $count, [
            'app'   => config('app.name'),
            'count' => $count,
        ]));
    }

    public function headers(): Headers
    {
        return new Headers(text: NotificationMails::unsubscribeHeaders(
            NotificationMails::unsubscribeUrl($this->user, 'all'),
        ));
    }

    public function content(): Content
    {
        $groups = $this->notifications->take(self::MAX_ITEMS)
            ->groupBy('category')
            ->map(fn (Collection $items, string $category) => [
                'label'          => NotificationText::category($category),
                'unsubscribeUrl' => NotificationMails::unsubscribeUrl($this->user, $category),
                'items'          => $items->map(fn (Notification $n) => [
                    'message' => NotificationText::message($n),
                    'lines'   => NotificationText::placementLines($n),
                    'url'     => URL::route('notifications.open', $n->id),
                ])->all(),
            ]);

        return new Content(markdown: 'mail.notification-digest', with: [
            'groups'      => $groups,
            'more'        => max(0, $this->notifications->count() - self::MAX_ITEMS),
            'inboxUrl'    => URL::route('notifications.index'),
            'settingsUrl' => URL::route('profile.edit').'#notifications',
        ]);
    }
}

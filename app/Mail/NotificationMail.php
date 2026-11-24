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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** One notification, emailed right away (category set to "Right away"). */
class NotificationMail extends Mailable
{
    use Queueable;

    public function __construct(public Notification $notification, public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.single_subject', [
            'app'     => config('app.name'),
            'message' => Str::limit(NotificationText::message($this->notification), 90),
        ]));
    }

    public function headers(): Headers
    {
        return new Headers(text: NotificationMails::unsubscribeHeaders(
            $this->unsubscribeUrl(),
        ));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification', with: [
            'message'        => NotificationText::message($this->notification),
            'lines'          => NotificationText::placementLines($this->notification),
            'url'            => URL::route('notifications.open', $this->notification->id),
            'settingsUrl'    => URL::route('profile.edit').'#notifications',
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    private function unsubscribeUrl(): string
    {
        return NotificationMails::unsubscribeUrl($this->user, $this->notification->category);
    }
}

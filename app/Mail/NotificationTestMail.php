<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent from Admin → Notifications to check the mailer works. */
class NotificationTestMail extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.test_subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification-test');
    }
}

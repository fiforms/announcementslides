<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/** Shared bits of the notification emails: one-click unsubscribe links. */
class NotificationMails
{
    /** A permanent signed link that turns off email for one category ('all' = every category). */
    public static function unsubscribeUrl(User $user, string $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', ['user' => $user->id, 'category' => $category]);
    }

    /** RFC 8058 one-click unsubscribe headers (mail clients POST to the URL). */
    public static function unsubscribeHeaders(string $url): array
    {
        return [
            'List-Unsubscribe'      => "<$url>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}

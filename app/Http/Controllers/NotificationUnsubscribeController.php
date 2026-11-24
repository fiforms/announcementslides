<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\NotificationPreferences;
use App\Support\NotificationText;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed "stop emails like this" link in notification emails. Works
 * without signing in. GET only shows a confirm button (link scanners in
 * mail systems follow GETs); POST turns the email off — the button, and
 * mail clients' RFC 8058 one-click unsubscribe, which is why the POST route
 * is exempt from CSRF.
 */
class NotificationUnsubscribeController extends Controller
{
    public function show(Request $request, User $user, string $category): Response
    {
        return $this->page($request, $user, $category, done: false);
    }

    public function store(Request $request, User $user, string $category)
    {
        $categories = $category === 'all'
            ? array_keys(NotificationPreferences::forUser($user))
            : [$category];

        NotificationPreferences::save($user, collect($categories)
            ->mapWithKeys(fn ($c) => [$c => ['email' => 'none']])->all());

        // Mail clients' one-click POST just needs a 2xx.
        if (! $request->header('X-Inertia') && $request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent();
        }

        return $this->page($request, $user, $category, done: true);
    }

    private function page(Request $request, User $user, string $category, bool $done): Response
    {
        abort_unless($category === 'all' || NotificationPreferences::exists($category), 404);

        $locale = NotificationText::localeFor($user);

        return Inertia::render('Notifications/Unsubscribe', [
            'done'     => $done,
            'category' => $category === 'all' ? null : NotificationText::category($category, $locale),
            'postUrl'  => $request->fullUrl(),
        ]);
    }
}

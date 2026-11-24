<?php

namespace App\Http\Middleware;

use App\Support\NotificationText;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a signed-in user's language (from the browser's Accept-Language,
 * the same signal vue-i18n uses) as the `locale` setting, once per session,
 * so emails sent later from a queue can be written in it.
 */
class RememberUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $request->session()->get('locale_remembered')) {
            $locale = $request->getPreferredLanguage(NotificationText::LOCALES) ?? 'en';
            $locale = substr($locale, 0, 2);

            if ($user->setting('locale') !== $locale) {
                $user->putSetting('locale', $locale);
            }
            $request->session()->put('locale_remembered', true);
        }

        return $next($request);
    }
}

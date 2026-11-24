<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\NotificationTestMail;
use App\Models\Notification;
use App\Support\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Site-wide notification settings: email on/off, digest time, retention. */
class NotificationSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/NotificationSettings', [
            'settings' => NotificationSettings::all(),
            'mailer'   => config('mail.default'),
            'mailFrom' => config('mail.from.address'),
            'stats'    => [
                'inbox'          => Notification::inbox()->count(),
                'archived'       => Notification::archived()->count(),
                'pending_digest' => Notification::where('email_mode', 'daily')->whereNull('emailed_at')->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email_enabled'  => 'required|boolean',
            'digest_time'    => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'retention_days' => 'required|integer|min:1|max:3650',
            'inbox_max_days' => 'required|integer|min:0|max:3650',
        ]);

        NotificationSettings::save($data);

        return back()->with('success', 'Notification settings saved.');
    }

    /** Send a test email to the signed-in admin, synchronously, so errors show here. */
    public function test(Request $request): RedirectResponse
    {
        try {
            Mail::to($request->user())->send(new NotificationTestMail);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Sending failed: '.$e->getMessage());
        }

        return back()->with('success', 'Test email sent to '.$request->user()->email.'.');
    }
}

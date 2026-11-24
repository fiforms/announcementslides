<?php

namespace Tests\Feature;

use App\Mail\NotificationDigestMail;
use App\Mail\NotificationMail;
use App\Mail\NotificationMails;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifier;
use App\Support\NotificationPreferences;
use App\Support\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        NotificationSettings::save(['email_enabled' => true]);
    }

    public function test_immediate_email_is_sent_per_recipient_and_marked(): void
    {
        Mail::fake();
        $a = User::factory()->create();
        $b = User::factory()->create();

        // slide_review defaults to "Right away".
        Notifier::send('slide_review', 'slide_approved', [$a, $b], ['params' => ['slide' => 'Picnic'], 'url' => '/']);

        Mail::assertSent(NotificationMail::class, 2);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo($a->email));
        $this->assertSame(0, Notification::whereNull('emailed_at')->count());
    }

    public function test_immediate_email_renders_in_the_users_language(): void
    {
        $user = User::factory()->create();
        $user->putSetting('locale', 'es');
        Notifier::send('slide_review', 'slide_approved', [$user], ['params' => ['slide' => 'Picnic']]);
        $n = Notification::forUser($user)->firstOrFail();

        $mail = (new NotificationMail($n, $user))->locale('es');
        $mail->assertSeeInHtml('fue aprobada');
        $mail->assertHasSubject(config('app.name').': Su diapositiva «Picnic» fue aprobada y publicada.');
    }

    public function test_nothing_is_emailed_while_site_email_is_off(): void
    {
        Mail::fake();
        NotificationSettings::save(['email_enabled' => false]);
        $user = User::factory()->create();

        Notifier::send('slide_review', 'slide_approved', [$user], ['params' => ['slide' => 'X']]);
        $this->artisan('notifications:send-digest')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_daily_digest_groups_unread_and_skips_read_and_muted(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'admin']);

        // team and admin_review_queue default to the daily digest.
        Notifier::send('team', 'user_joined', [$user], ['params' => ['user' => 'Ann', 'email' => 'a@x']]);
        Notifier::send('team', 'user_joined', [$user], ['params' => ['user' => 'Bob', 'email' => 'b@x']]);
        Notifier::send('admin_review_queue', 'slide_pending', [$user], ['params' => ['user' => 'Cy', 'slide' => 'S']]);
        Mail::assertNothingSent();

        Notification::forUser($user)->where('data', 'like', '%Bob%')->first()->markAsRead();
        NotificationPreferences::save($user, ['admin_review_queue' => ['email' => 'none']]);

        $this->artisan('notifications:send-digest')->assertSuccessful();

        Mail::assertSent(NotificationDigestMail::class, function (NotificationDigestMail $m) use ($user) {
            return $m->hasTo($user->email)
                && $m->notifications->count() === 1
                && $m->notifications->first()->data['params']['user'] === 'Ann';
        });
        $this->assertSame(0, Notification::forUser($user)->whereNull('emailed_at')->count());

        // Nothing left: a second run sends nothing more.
        $this->artisan('notifications:send-digest')->assertSuccessful();
        Mail::assertSent(NotificationDigestMail::class, 1);
    }

    public function test_digest_renders(): void
    {
        $user = User::factory()->create();
        Notifier::send('team', 'user_joined', [$user], ['params' => ['user' => 'Ann', 'email' => 'a@x']]);

        $mail = new NotificationDigestMail(Notification::forUser($user)->get(), $user);
        $mail->assertSeeInHtml('Ann (a@x) accepted your invitation');
        $mail->assertHasSubject(config('app.name').': 1 new notification');
    }

    public function test_signed_unsubscribe_link_turns_email_off(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $url = NotificationMails::unsubscribeUrl($user, 'team');

        // GET only asks; it changes nothing.
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Notifications/Unsubscribe')->where('done', false));
        $this->assertSame('daily', NotificationPreferences::forUser($user)['team']['email']);

        $this->post($url)->assertOk()->assertInertia(fn ($p) => $p->where('done', true));
        $this->assertSame('none', NotificationPreferences::forUser($user)['team']['email']);
    }

    public function test_one_click_unsubscribe_all_and_bad_signatures(): void
    {
        $user = User::factory()->create();

        $this->post(NotificationMails::unsubscribeUrl($user, 'all'), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        foreach (NotificationPreferences::forUser($user) as $pref) {
            $this->assertSame('none', $pref['email']);
        }

        $this->get(route('notifications.unsubscribe', ['user' => $user->id, 'category' => 'team']))->assertForbidden();
    }

    public function test_locale_is_remembered_from_the_browser(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Accept-Language', 'es-MX,es;q=0.9')->get(route('notifications.index'));

        $this->assertSame('es', $user->setting('locale'));
    }

    public function test_admin_settings_page_saves_and_sends_test(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk();
        $this->actingAs($admin)->patch(route('admin.notifications.update'), [
            'email_enabled' => false, 'digest_time' => '06:30', 'retention_days' => 30, 'inbox_max_days' => 0,
            'device_temp_threshold_c' => 75,
        ])->assertRedirect();

        $this->assertSame('06:30', NotificationSettings::get('digest_time'));
        $this->assertSame(30, NotificationSettings::get('retention_days'));
        $this->assertSame(75, NotificationSettings::get('device_temp_threshold_c'));
        $this->assertFalse(NotificationSettings::emailEnabled());

        $this->actingAs($admin)->post(route('admin.notifications.test'))->assertRedirect();
        Mail::assertSent(\App\Mail\NotificationTestMail::class);

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get(route('admin.notifications.index'))->assertForbidden();
    }
}

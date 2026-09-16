<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Breeze scaffolds its post-auth redirects as route('dashboard'), a name this
 * app never registered — the only dashboard is `admin.dashboard`, behind
 * EnsureAdmin. Every call site threw RouteNotFoundException and 500'd, which
 * took out registration, email verification and password confirmation.
 *
 * These assert the destination directly, so a reintroduced phantom route name
 * fails here rather than in production.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_route_named_dashboard_exists(): void
    {
        // Guards the root cause: if someone registers a `dashboard` route the
        // rest of these tests would start passing for the wrong reason.
        $this->assertFalse(
            app('router')->has('dashboard'),
            'A route named `dashboard` exists again; the redirects below are now ambiguous.'
        );
    }

    public function test_registration_lands_on_the_slide_board_and_does_not_500(): void
    {
        $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new@example.org',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('slides.index', absolute: false));

        $this->assertAuthenticated();
        $this->assertSame('viewer', User::where('email', 'new@example.org')->value('role'));
    }

    public function test_a_viewer_logging_in_lands_on_the_slide_board(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('slides.index', absolute: false));
    }

    /** Admins keep the destination AuthenticatedSessionController already gave them. */
    public function test_an_admin_logging_in_lands_on_the_admin_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_password_confirmation_redirects_instead_of_500ing(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect(route('slides.index', absolute: false));
    }

    public function test_email_verification_redirects_instead_of_500ing(): void
    {
        Event::fake();
        $user = User::factory()->create(['role' => 'viewer', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('slides.index', absolute: false).'?verified=1');

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /** An admin verifying an email should land where an admin logging in lands. */
    public function test_an_admin_verifying_email_lands_on_the_admin_dashboard(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $admin->id,
            'hash' => sha1($admin->email),
        ]);

        $this->actingAs($admin)->get($url)
            ->assertRedirect(route('admin.dashboard', absolute: false).'?verified=1');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The first-run wizard exists only while there are no users. The cases that
 * matter most are the gate (it must 404 forever once a user exists, on GET and
 * POST), the optional token, and that it creates an *admin*.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name'                  => 'First Admin',
            'email'                 => 'first@example.org',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'install_widgets'       => false,
        ], $overrides);
    }

    // ── The gate ────────────────────────────────────────────────────────────

    public function test_the_wizard_renders_on_an_empty_install(): void
    {
        $this->get(route('setup.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Setup/Index')
                ->where('tokenRequired', false)
                ->where('widgets', ['clock', 'calendar', 'weather']));
    }

    public function test_it_is_gone_once_any_user_exists_on_both_get_and_post(): void
    {
        User::factory()->create();

        $this->get(route('setup.show'))->assertNotFound();
        $this->post(route('setup.store'), $this->payload())->assertNotFound();
        $this->assertSame(1, User::count());
    }

    public function test_a_post_after_the_first_admin_exists_cannot_make_another_admin(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('admin.dashboard'));
        $this->post(route('setup.store'), $this->payload(['email' => 'second@example.org']))->assertNotFound();

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    // ── What it creates ─────────────────────────────────────────────────────

    public function test_it_creates_a_verified_admin_and_signs_them_in(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('admin.dashboard'));

        $user = User::firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertSame('first@example.org', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertAuthenticatedAs($user);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_password_can_be_used_to_log_in_again(): void
    {
        $this->post(route('setup.store'), $this->payload());
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => 'first@example.org', 'password' => 'correct-horse-battery'])
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_invalid_input_creates_nothing(): void
    {
        $this->post(route('setup.store'), $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        $this->post(route('setup.store'), $this->payload(['password_confirmation' => 'different-value']))
            ->assertSessionHasErrors('password');
        $this->post(route('setup.store'), $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    // ── The token ───────────────────────────────────────────────────────────

    public function test_with_a_token_configured_the_form_asks_for_it(): void
    {
        config(['setup.token' => 's3cret']);

        $this->get(route('setup.show'))->assertInertia(fn (Assert $p) => $p->where('tokenRequired', true));
    }

    public function test_a_missing_or_wrong_token_is_refused_and_creates_nothing(): void
    {
        config(['setup.token' => 's3cret']);

        $this->post(route('setup.store'), $this->payload())->assertSessionHasErrors('token');
        $this->post(route('setup.store'), $this->payload(['token' => 'guess']))->assertSessionHasErrors('token');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_the_right_token_is_accepted(): void
    {
        config(['setup.token' => 's3cret']);

        $this->post(route('setup.store'), $this->payload(['token' => 's3cret']))
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSame('admin', User::firstOrFail()->role);
    }

    public function test_an_empty_token_setting_means_open(): void
    {
        // compose.yaml passes SETUP_TOKEN through as "" when it is not set.
        $this->assertNull(config('setup.token'));
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('admin.dashboard'));
    }

    // ── Widgets ─────────────────────────────────────────────────────────────

    public function test_it_installs_the_bundled_widgets_when_asked(): void
    {
        $this->post(route('setup.store'), $this->payload(['install_widgets' => true]))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing('error');

        $this->assertEqualsCanonicalizing(['clock', 'calendar', 'weather'], Widget::pluck('slug')->all());
        $this->assertSame(User::firstOrFail()->id, Widget::first()->installed_by);
    }

    public function test_it_installs_no_widgets_when_not_asked(): void
    {
        $this->post(route('setup.store'), $this->payload(['install_widgets' => false]));

        $this->assertSame(0, Widget::count());
    }

    public function test_a_missing_widget_folder_is_reported_but_does_not_block_setup(): void
    {
        config(['setup.widgets' => ['clock', 'does-not-exist']]);

        // A folder that isn't there is simply not offered/installed.
        $this->post(route('setup.store'), $this->payload(['install_widgets' => true]))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(['clock'], Widget::pluck('slug')->all());
        $this->assertSame(1, User::count());
    }

    // ── The redirect ────────────────────────────────────────────────────────

    public function test_entry_pages_redirect_to_setup_while_empty(): void
    {
        config(['setup.redirect' => true]);

        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get('/login')->assertRedirect(route('setup.show'));
        $this->get('/register')->assertRedirect(route('setup.show'));
    }

    public function test_other_routes_and_methods_are_not_redirected(): void
    {
        config(['setup.redirect' => true]);

        $this->get('/up')->assertOk();
        $this->get(route('setup.show'))->assertOk();
        $this->post('/login', ['email' => 'a@b.co', 'password' => 'x'])->assertSessionHasErrors();
    }

    public function test_nothing_redirects_once_a_user_exists(): void
    {
        config(['setup.redirect' => true]);
        User::factory()->create();

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_the_redirect_can_be_turned_off(): void
    {
        config(['setup.redirect' => false]);

        $this->get('/login')->assertOk();
    }

    // ── Password login form switch ──────────────────────────────────────────

    public function test_the_login_form_flag_follows_the_config(): void
    {
        config(['auth.password_login' => true]);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('passwordLogin', true));

        config(['auth.password_login' => false]);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('passwordLogin', false));
    }
}

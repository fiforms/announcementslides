<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AboutInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_open_the_about_page(): void
    {
        config(['app.name' => 'AnnouncementSlides', 'app.version' => '1.2.3']);

        $this->get(route('about'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('About')
                ->where('about.branded', false)
                ->where('about.version', '1.2.3')
                ->where('about.repo_url', AboutInfo::REPO_URL));
    }

    public function test_a_renamed_site_is_branded(): void
    {
        config(['app.name' => 'Example Screens']);

        $this->get(route('about'))
            ->assertInertia(fn (Assert $page) => $page->where('about.branded', true));
    }

    public function test_the_product_name_in_any_spelling_is_not_branded(): void
    {
        foreach (['AnnouncementSlides', 'Announcement Slides', 'announcement-slides'] as $name) {
            $this->assertFalse(AboutInfo::isBranded($name), $name);
        }
        $this->assertTrue(AboutInfo::isBranded('Example Screens'));
    }

    public function test_admins_get_the_system_details_and_others_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.about'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/About')
                ->has('system.laravel')
                ->has('system.php'));

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->get(route('admin.about'))
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_log_in_for_the_admin_about_tab(): void
    {
        $this->get(route('admin.about'))->assertRedirect(route('login'));
    }
}

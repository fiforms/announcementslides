<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QrCodePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_signed_in_user_can_open_the_qr_code_creator(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('qr-code'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('QrCode'));
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('qr-code'))->assertRedirect(route('login'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Notification;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlideScheduleNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_start_and_natural_expiry_notify_uploader_and_church_managers_once(): void
    {
        $entity = Entity::create(['name' => 'Hope Church']);
        $leader = User::factory()->create();
        $editor = User::factory()->create();
        $follower = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $editor->entities()->attach($entity->id, ['role' => 'editor']);
        $follower->entities()->attach($entity->id, ['role' => 'viewer']);

        $slide = Slide::create(['title' => 'VBS', 'status' => 'published', 'entity_id' => $entity->id,
            'uploaded_by' => $leader->id, 'publish_at' => now()->addHour(), 'expires_at' => now()->addDays(2)]);

        $this->artisan('notifications:slide-schedule');
        $this->assertSame(0, Notification::count());

        $this->travel(2)->hours();
        $this->artisan('notifications:slide-schedule');
        $this->artisan('notifications:slide-schedule');

        $this->assertSame(['slide_started'], Notification::forUser($leader)->pluck('type')->all());
        $this->assertSame(['slide_started'], Notification::forUser($editor)->pluck('type')->all());
        $this->assertSame(0, Notification::forUser($follower)->count());

        $this->travel(3)->days();
        $this->artisan('notifications:slide-schedule');

        $this->assertSame(1, Notification::forUser($editor)->where('type', 'slide_expired')->count());
    }

    public function test_unscheduled_slides_and_manual_archives_are_not_announced(): void
    {
        $user = User::factory()->create(['role' => 'contributor']);
        $plain = Slide::create(['title' => 'Now', 'status' => 'published', 'uploaded_by' => $user->id, 'publish_at' => now()]);
        $archived = Slide::create(['title' => 'Old', 'status' => 'published', 'uploaded_by' => $user->id]);

        $this->travel(1)->minutes();
        $archived->update(['expires_at' => now()]);
        $this->travel(1)->minutes();

        $this->artisan('notifications:slide-schedule');

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($plain->fresh()->start_notified_at);
        $this->assertNotNull($archived->fresh()->expiry_notified_at);
    }

    public function test_long_missed_times_are_marked_without_notice_and_drafts_ignored(): void
    {
        $user = User::factory()->create();
        $old = Slide::create(['title' => 'Old', 'status' => 'published', 'uploaded_by' => $user->id, 'expires_at' => now()->addDay()]);
        $draft = Slide::create(['title' => 'Draft', 'status' => 'draft', 'uploaded_by' => $user->id, 'expires_at' => now()->addDay()]);

        $this->travel(5)->days();
        $this->artisan('notifications:slide-schedule');

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($old->fresh()->expiry_notified_at);
        $this->assertNull($draft->fresh()->expiry_notified_at);
    }
}

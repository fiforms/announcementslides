<?php

namespace Tests\Feature;

use App\Jobs\AnnounceSlideAvailability;
use App\Jobs\DistributeGlobalSeparateShow;
use App\Jobs\SyncShowAutoFillForSlide;
use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Notification;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlideAvailabilityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function church(string $name, string $role = 'admin', array $attrs = []): array
    {
        $entity = Entity::create(['name' => $name] + $attrs);
        $user = User::factory()->create();
        $user->entities()->attach($entity->id, ['role' => $role]);

        return [$entity, $user, Show::mainFor($entity)];
    }

    private function only(User $user): Notification
    {
        $this->assertSame(1, Notification::forUser($user)->count(), 'expected exactly one notification');

        return Notification::forUser($user)->first();
    }

    public function test_publishing_a_global_slide_tells_managers_where_it_landed(): void
    {
        [$a, $leaderA] = $this->church('Alpha Church');
        [$b, $leaderB, $mainB] = $this->church('Beta Church');
        $mainB->update(['auto_fill_global' => false]);
        $editorA = User::factory()->create();
        $editorA->entities()->attach($a->id, ['role' => 'editor']);
        $follower = User::factory()->create();
        $follower->entities()->attach($a->id, ['role' => 'viewer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create();

        $slide = Slide::create(['title' => 'Camp Meeting', 'status' => 'pending', 'uploaded_by' => $viewer->id]);
        $this->actingAs($admin)->post(route('admin.slides.approve', $slide));

        $n = $this->only($leaderA);
        $this->assertSame('global_slide', $n->type);
        $this->assertSame([['entity' => 'Alpha Church', 'shows' => ['Main Show']]], $n->data['params']['placements']);
        $this->assertSame($slide->id, $n->data['slide_id']);
        $this->assertSame('global_slide', $this->only($editorA)->type);

        $this->assertSame([['entity' => 'Beta Church', 'shows' => []]], $this->only($leaderB)->data['params']['placements']);
        $this->assertSame(0, Notification::forUser($follower)->count());
        // The submitter hears it was approved, not that it's "new".
        $this->assertSame('slide_approved', $this->only($viewer)->type);
        // Approved by an admin: no "published without review" notice for admins.
        $this->assertSame(0, Notification::forUser($admin)->count());
        $this->assertNotNull($slide->fresh()->announced_at);
    }

    public function test_re_running_the_fan_out_does_not_announce_twice(): void
    {
        [, $leader] = $this->church('Alpha Church');
        $slide = Slide::create(['title' => 'G', 'status' => 'published', 'uploaded_by' => User::factory()->create(['role' => 'admin'])->id]);

        SyncShowAutoFillForSlide::dispatchSync($slide->id);
        SyncShowAutoFillForSlide::dispatchSync($slide->id);
        $slide->update(['status' => 'draft']);
        SyncShowAutoFillForSlide::dispatchSync($slide->id);
        $slide->update(['status' => 'published']);
        SyncShowAutoFillForSlide::dispatchSync($slide->id);

        $this->only($leader);
    }

    public function test_drafts_are_not_announced_until_published(): void
    {
        [, $leader] = $this->church('Alpha Church');
        $slide = Slide::create(['title' => 'G', 'status' => 'draft', 'uploaded_by' => User::factory()->create()->id]);

        SyncShowAutoFillForSlide::dispatchSync($slide->id);
        $this->assertSame(0, Notification::forUser($leader)->count());

        $slide->update(['status' => 'published']);
        SyncShowAutoFillForSlide::dispatchSync($slide->id);
        $this->only($leader);
    }

    public function test_a_global_slide_not_added_to_shows_is_still_announced(): void
    {
        [, $leader] = $this->church('Alpha Church');
        $slide = Slide::create(['title' => 'G', 'status' => 'published', 'uploaded_by' => User::factory()->create(['role' => 'admin'])->id,
            'publish_at' => now()->addDays(3)]);

        AnnounceSlideAvailability::dispatchSync($slide->id);

        $params = $this->only($leader)->data['params'];
        $this->assertSame([], $params['placements'][0]['shows']);
        $this->assertSame(now()->addDays(3)->toDateString(), $params['starts_at']);
    }

    public function test_contributor_published_global_slide_tells_admins(): void
    {
        $this->church('Alpha Church');
        $admin = User::factory()->create(['role' => 'admin']);
        $contributor = User::factory()->create(['role' => 'contributor']);
        $slide = Slide::create(['title' => 'G', 'status' => 'published', 'uploaded_by' => $contributor->id]);

        SyncShowAutoFillForSlide::dispatchSync($slide->id);

        $this->assertSame('admin_global_published', $this->only($admin)->type);
    }

    public function test_sharing_with_nearby_churches_tells_their_managers_and_admins(): void
    {
        [$home, $homeLeader] = $this->church('Home Church', 'admin', ['latitude' => 35.0, 'longitude' => -80.0]);
        [, $nearLeader] = $this->church('Near Church', 'admin', ['latitude' => 35.1, 'longitude' => -80.1]);
        [, $farLeader] = $this->church('Far Church', 'admin', ['latitude' => 45.0, 'longitude' => -100.0]);
        $admin = User::factory()->create(['role' => 'admin']);

        $slide = Slide::create(['title' => 'Potluck', 'status' => 'published', 'entity_id' => $home->id,
            'share_nearby' => true, 'uploaded_by' => $homeLeader->id]);
        AnnounceSlideAvailability::dispatchSync($slide->id, Show::syncAutoFillForSlide($slide));

        $n = $this->only($nearLeader);
        $this->assertSame('nearby_slide', $n->type);
        $this->assertSame('Home Church', $n->data['params']['entity']);
        $this->assertSame(['Main Show'], $n->data['params']['placements'][0]['shows']);
        $this->assertSame(0, Notification::forUser($farLeader)->count());
        $this->assertSame(0, Notification::forUser($homeLeader)->count());
        $this->assertSame('admin_slide_shared', $this->only($admin)->type);
    }

    public function test_a_churchs_own_unshared_slide_is_not_announced(): void
    {
        [$home, $leader] = $this->church('Home Church');
        [, $other] = $this->church('Other Church');
        $slide = Slide::create(['title' => 'Local', 'status' => 'published', 'entity_id' => $home->id, 'uploaded_by' => $leader->id]);

        AnnounceSlideAvailability::dispatchSync($slide->id);

        $this->assertSame(0, Notification::count());
        $this->assertNull($slide->fresh()->announced_at);
    }

    public function test_promo_show_is_announced_once_per_batch(): void
    {
        [, $leader] = $this->church('Alpha Church');
        $admin = User::factory()->create(['role' => 'admin']);
        $template = GlobalShowTemplate::create(['name' => 'Camp 2026', 'created_by' => $admin->id]);
        $first = Slide::create(['title' => 'Day 1', 'status' => 'published', 'uploaded_by' => $admin->id]);
        $second = Slide::create(['title' => 'Day 2', 'status' => 'published', 'uploaded_by' => $admin->id]);
        $draft = Slide::create(['title' => 'Draft', 'status' => 'draft', 'uploaded_by' => $admin->id]);

        DistributeGlobalSeparateShow::dispatchSync($template->id, $first->id);
        DistributeGlobalSeparateShow::dispatchSync($template->id, $second->id);
        DistributeGlobalSeparateShow::dispatchSync($template->id, $draft->id);

        $n = $this->only($leader);
        $this->assertSame('promo_available', $n->type);
        $this->assertSame('Camp 2026', $n->data['params']['promo']);
        $this->assertSame(['Camp 2026'], $n->data['params']['placements'][0]['shows']);

        // Later additions get their own notice.
        $this->travel(15)->minutes();
        $later = Slide::create(['title' => 'Day 3', 'status' => 'published', 'uploaded_by' => $admin->id]);
        DistributeGlobalSeparateShow::dispatchSync($template->id, $later->id);
        $this->assertSame('promo_slide_added', Notification::forUser($leader)->latest()->first()->type);

        // Publishing the draft later announces it as part of the promo, not as a global slide.
        $draft->update(['status' => 'published']);
        SyncShowAutoFillForSlide::dispatchSync($draft->id);
        $this->assertSame(0, Notification::forUser($leader)->where('type', 'global_slide')->count());
        $this->assertSame(2, Notification::forUser($leader)->where('type', 'promo_slide_added')->count());
    }

    public function test_deactivated_churches_are_skipped(): void
    {
        [$entity, $leader] = $this->church('Closed Church');
        $entity->forceFill(['deactivated' => true])->save();
        $slide = Slide::create(['title' => 'G', 'status' => 'published', 'uploaded_by' => User::factory()->create(['role' => 'admin'])->id]);

        SyncShowAutoFillForSlide::dispatchSync($slide->id);

        $this->assertSame(0, Notification::forUser($leader)->count());
    }
}

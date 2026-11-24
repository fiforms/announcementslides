<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityInvitation;
use App\Models\Notification;
use App\Models\Slide;
use App\Models\User;
use App\Services\Notifier;
use App\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notify(User $user, string $category = 'team', string $type = 'user_joined'): Notification
    {
        Notifier::send($category, $type, [$user], ['params' => ['user' => 'X'], 'url' => '/profile']);

        return Notification::forUser($user)->latest()->firstOrFail();
    }

    public function test_one_event_creates_one_row_per_recipient_each_cleared_independently(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);

        $created = Notifier::send('admin_review_queue', 'slide_pending', [$a, $b, $a->id], ['params' => []]);

        $this->assertSame(2, $created);
        $na = Notification::forUser($a)->firstOrFail();

        $this->actingAs($a)->post(route('notifications.archive', $na->id))->assertRedirect();

        $this->assertNotNull($na->fresh()->archived_at);
        $this->assertNotNull($na->fresh()->read_at);
        $this->assertSame(1, Notification::forUser($b)->inbox()->count());
    }

    public function test_banned_users_and_non_admins_in_admin_categories_are_skipped(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $banned = User::factory()->create(['role' => 'banned']);

        $this->assertSame(0, Notifier::send('admin_review_queue', 'slide_pending', [$viewer, $banned], ['params' => []]));
        $this->assertSame(0, Notifier::send('team', 'user_joined', [$banned], ['params' => []]));
    }

    public function test_preferences_mute_or_archive_on_arrival(): void
    {
        $muted = User::factory()->create();
        $emailOnly = User::factory()->create();
        NotificationPreferences::save($muted, ['team' => ['inbox' => false, 'email' => 'none']]);
        NotificationPreferences::save($emailOnly, ['team' => ['inbox' => false, 'email' => 'daily']]);
        \App\Support\NotificationSettings::save(['email_enabled' => true]);

        Notifier::send('team', 'user_joined', [$muted, $emailOnly], ['params' => []]);

        $this->assertSame(0, Notification::forUser($muted)->count());
        $row = Notification::forUser($emailOnly)->firstOrFail();
        $this->assertNotNull($row->archived_at);
        $this->assertSame('daily', $row->email_mode);
    }

    public function test_email_mode_is_none_while_site_email_is_off(): void
    {
        $user = User::factory()->create();

        $this->assertSame('none', $this->notify($user)->email_mode);
    }

    public function test_users_cannot_touch_each_others_notifications(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $n = $this->notify($owner);

        $this->actingAs($other)->post(route('notifications.archive', $n->id))->assertNotFound();
        $this->actingAs($other)->get(route('notifications.open', $n->id))->assertNotFound();
        $this->assertNull($n->fresh()->archived_at);
    }

    public function test_open_marks_read_and_redirects_only_within_the_site(): void
    {
        $user = User::factory()->create();
        $n = $this->notify($user);

        $this->actingAs($user)->get(route('notifications.open', $n->id))->assertRedirect('/profile');
        $this->assertNotNull($n->fresh()->read_at);

        $evil = $this->notify($user);
        $evil->forceFill(['data' => ['url' => '//evil.example']])->save();
        $this->actingAs($user)->get(route('notifications.open', $evil->id))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_clear_all_restore_and_inbox_listing(): void
    {
        $user = User::factory()->create();
        $first = $this->notify($user);
        $this->notify($user);

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Notifications/Index')->has('notifications.data', 2));

        $this->actingAs($user)->post(route('notifications.archive-all'))->assertRedirect();
        $this->assertSame(0, Notification::forUser($user)->inbox()->count());
        $this->assertSame(0, $user->unreadNotificationCount());

        $this->actingAs($user)->post(route('notifications.restore', $first->id));
        $this->actingAs($user)->get(route('notifications.index', ['tab' => 'archive']))
            ->assertInertia(fn ($p) => $p->has('notifications.data', 1));
    }

    public function test_recent_endpoint_and_unread_shared_prop(): void
    {
        $user = User::factory()->create();
        $this->notify($user);

        $this->actingAs($user)->getJson(route('notifications.recent'))
            ->assertOk()->assertJsonPath('unread', 1)->assertJsonCount(1, 'notifications');

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertInertia(fn ($p) => $p->where('unreadNotifications', 1));
    }

    public function test_profile_saves_preferences_only_for_eligible_categories(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->patch(route('profile.notifications.update'), ['preferences' => [
            'slide_review' => ['inbox' => false, 'email' => 'daily'],
            'admin_review_queue' => ['inbox' => true, 'email' => 'immediate'],
        ]])->assertRedirect();

        $prefs = NotificationPreferences::forUser($viewer);
        $this->assertSame(['inbox' => false, 'email' => 'daily'], $prefs['slide_review']);
        $this->assertArrayNotHasKey('admin_review_queue', $prefs);
        $this->assertNull($viewer->setting('notify.admin_review_queue.email'));
    }

    public function test_prune_deletes_old_archived_and_archives_stale_inbox(): void
    {
        $user = User::factory()->create();
        $old = $this->notify($user);
        $old->forceFill(['archived_at' => now()->subDays(91)])->save();
        $stale = $this->notify($user);
        $stale->forceFill(['created_at' => now()->subDays(200)])->save();
        $fresh = $this->notify($user);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertNull(Notification::find($old->id));
        $this->assertNotNull($stale->fresh()->archived_at);
        $this->assertNull($fresh->fresh()->archived_at);
    }

    // ── Event hooks ──────────────────────────────────────────────────────

    public function test_approving_and_rejecting_notify_the_uploader(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $a = Slide::create(['title' => 'Picnic', 'status' => 'pending', 'uploaded_by' => $viewer->id]);
        $b = Slide::create(['title' => 'Concert', 'status' => 'pending', 'uploaded_by' => $viewer->id]);

        $this->actingAs($admin)->post(route('admin.slides.approve', $a));
        $this->actingAs($admin)->post(route('admin.slides.reject', $b));

        $types = Notification::forUser($viewer)->pluck('type')->sort()->values()->all();
        $this->assertSame(['slide_approved', 'slide_rejected'], $types);
        $approved = Notification::forUser($viewer)->where('type', 'slide_approved')->first();
        $this->assertSame('Picnic', $approved->data['params']['slide']);
        $this->assertSame($a->id, $approved->data['slide_id']);
    }

    public function test_redeemed_entity_invitation_notifies_inviter_and_leaders(): void
    {
        $entity = Entity::create(['name' => 'Hope Church']);
        $leader = User::factory()->create();
        $coLeader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $coLeader->entities()->attach($entity->id, ['role' => 'admin']);
        EntityInvitation::create(['email' => 'new@example.com', 'entity_id' => $entity->id, 'role' => 'editor', 'invited_by' => $leader->id]);

        $new = User::factory()->create(['email' => 'new@example.com']);
        EntityInvitation::redeemFor($new);

        $mine = Notification::forUser($leader)->firstOrFail();
        $this->assertSame('editor_joined', $mine->type);
        $this->assertTrue($mine->data['params']['by_you']);
        $this->assertSame($leader->name, Notification::forUser($coLeader)->firstOrFail()->data['params']['by']);
        $this->assertSame(0, Notification::forUser($new)->count());
    }

    public function test_adding_an_existing_user_as_editor_tells_them(): void
    {
        $entity = Entity::create(['name' => 'Hope Church']);
        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $user = User::factory()->create();

        $this->actingAs($leader)->post(route('entity.people.store', $entity), ['email' => $user->email]);

        $this->assertSame('added_as_editor', Notification::forUser($user)->value('type'));
        // The leader did it themselves; no other leaders to tell.
        $this->assertSame(1, Notification::forUser($leader)->count());
        $this->assertTrue(Notification::forUser($leader)->first()->data['params']['by_you']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Slide;
use App\Models\User;
use App\Support\SlideToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SlideTokenTest extends TestCase
{
    use RefreshDatabase;

    private function slide(array $attrs = []): Slide
    {
        return Slide::create($attrs + ['title' => 'T', 'status' => 'published', 'uploaded_by' => User::factory()->create()->id]);
    }

    public function test_new_slides_get_a_vowel_free_six_character_token_with_a_letter(): void
    {
        foreach (range(1, 40) as $_) {
            $token = $this->slide()->token;
            $this->assertMatchesRegularExpression('/^[bcdfghjkmnpqrstvwxz2567]{6}$/', $token);
            $this->assertMatchesRegularExpression('/[a-z]/', $token);
        }
    }

    public function test_tokens_are_unique_and_a_token_cannot_be_changed(): void
    {
        $a = $this->slide();
        $b = $this->slide();
        $this->assertNotSame($a->token, $b->token);

        $original = $a->token;
        $a->update(['token' => 'camp26', 'title' => 'Renamed']);
        $this->assertSame($original, $a->fresh()->token);
        $this->assertSame('Renamed', $a->fresh()->title);
    }

    public function test_generator_skips_tokens_already_taken_including_soft_deleted_slides(): void
    {
        $deleted = $this->slide(['token' => 'camp26']);
        $deleted->delete();

        $this->assertTrue(SlideToken::taken('camp26'));
    }

    public function test_public_urls_use_the_token_and_numeric_ids_no_longer_resolve(): void
    {
        $slide = $this->slide();

        $this->assertSame(url('/slides/' . $slide->token), route('slides.show', $slide));
        $this->get(route('slides.show', $slide->token))->assertOk();
        $this->get('/slides/' . $slide->id)->assertNotFound();
        $this->get('/slides/' . $slide->id . '/download')->assertNotFound();
    }

    public function test_a_link_opens_only_released_slides(): void
    {
        $live = $this->slide();
        $draft = $this->slide(['status' => 'draft']);
        $pending = $this->slide(['status' => 'pending']);
        $future = $this->slide(['publish_at' => now()->addDay()]);

        $this->get(route('slides.show', $live->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide.token', $live->token));

        foreach ([$draft, $pending, $future] as $hidden) {
            $this->get(route('slides.show', $hidden->token))
                ->assertInertia(fn (Assert $page) => $page->where('initialSlide', null));
            $this->get(route('slides.download', $hidden->token))->assertNotFound();
        }
    }

    public function test_an_entity_slide_does_not_open_for_non_members_but_does_for_members(): void
    {
        $entity = Entity::create(['name' => 'Local']);
        $slide = $this->slide(['entity_id' => $entity->id]);
        $member = User::factory()->create();
        $member->entities()->attach($entity->id, ['role' => 'viewer']);

        $this->get(route('slides.show', $slide->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide', null));
        $this->actingAs(User::factory()->create())->get(route('slides.show', $slide->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide', null));
        $this->actingAs($member)->get(route('slides.show', $slide->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide.token', $slide->token));
    }

    private function finalize(User $user, array $data)
    {
        return $this->actingAs($user)->postJson(route('uploads.finalize'), $data + ['title' => 'Camp Meeting']);
    }

    public function test_a_contributor_can_choose_a_token_and_it_is_stored(): void
    {
        $user = User::factory()->create(['role' => 'contributor']);

        $this->finalize($user, ['token' => 'camp26'])->assertOk();

        $this->assertSame('camp26', Slide::where('title', 'Camp Meeting')->value('token'));
    }

    public function test_a_chosen_token_must_be_unique_well_formed_and_not_reserved(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->slide(['token' => 'taken1']);

        foreach (['taken1', 'TAKEN!', '123456', 'ab', 'download-zip', 'two words'] as $bad) {
            $this->finalize($user, ['token' => $bad])->assertStatus(422)->assertJsonStructure(['message']);
        }
        $this->assertSame(1, Slide::count());
    }

    public function test_entity_leaders_and_viewers_cannot_choose_a_token(): void
    {
        $entity = Entity::create(['name' => 'Local']);
        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);

        $this->finalize($leader, ['token' => 'camp26', 'entity_id' => $entity->id])->assertForbidden();
        $this->finalize(User::factory()->create(), ['token' => 'camp26'])->assertForbidden();
        $this->assertSame(0, Slide::count());
    }

    public function test_shows_and_promos_get_unique_immutable_tokens(): void
    {
        $entity = Entity::create(['name' => 'Local']);
        $show = \App\Models\Show::mainFor($entity);
        $promo = \App\Models\GlobalShowTemplate::create(['name' => 'Camp']);

        foreach ([$show, $promo, \App\Models\Show::globalBoard()] as $model) {
            $this->assertMatchesRegularExpression('/^[bcdfghjkmnpqrstvwxz2567]{6}$/', $model->token);
        }

        $original = $show->token;
        $show->update(['token' => 'camp26', 'name' => 'Renamed']);
        $this->assertSame($original, $show->fresh()->token);
        $this->assertNotSame($show->token, $promo->token);
    }

    public function test_show_param_only_selects_a_show_of_the_requested_entity(): void
    {
        $a = Entity::create(['name' => 'A']);
        $b = Entity::create(['name' => 'B']);
        $showA = \App\Models\Show::mainFor($a);
        $showB = \App\Models\Show::mainFor($b);
        $user = User::factory()->create();
        $user->entities()->attach([$a->id => ['role' => 'viewer'], $b->id => ['role' => 'viewer']]);
        $this->actingAs($user);

        $this->get(route('slides.index', ['entity_id' => $a->id, 'show' => $showB->token]))
            ->assertInertia(fn (Assert $page) => $page->where('showToken', $showA->token));
        $this->get(route('slides.index', ['entity_id' => $a->id, 'show' => $showA->token]))
            ->assertInertia(fn (Assert $page) => $page->where('showToken', $showA->token));
        // Without an entity only global shows resolve; an entity's show token falls back to the Global Board.
        $this->get(route('slides.index', ['show' => $showA->token]))
            ->assertInertia(fn (Assert $page) => $page->where('showToken', \App\Models\Show::globalBoard()->token));
        // Numeric ids are no longer accepted.
        $this->get(route('slides.index', ['entity_id' => $a->id, 'show_id' => $showB->id]))
            ->assertInertia(fn (Assert $page) => $page->where('showToken', $showA->token));
    }

    public function test_a_downloads_unknown_show_or_promo_token_404s(): void
    {
        $this->get(route('slides.download-zip', ['show' => 'nope']))->assertNotFound();
        $this->get(route('slides.download-zip', ['promo' => 'nope']))->assertNotFound();
    }

    public function test_a_contributor_can_name_a_new_promo_and_it_must_be_unique(): void
    {
        $user = User::factory()->create(['role' => 'contributor']);
        \App\Models\GlobalShowTemplate::create(['name' => 'Old', 'token' => 'easter26']);

        $this->finalize($user, ['add_to_show' => 'separate', 'new_show_name' => 'Camp', 'promo_token' => 'easter26'])->assertStatus(422);
        $this->finalize($user, ['add_to_show' => 'main', 'promo_token' => 'camp26'])->assertStatus(422);
        $this->finalize($user, ['add_to_show' => 'separate', 'new_show_name' => 'Camp', 'promo_token' => 'camp26'])->assertOk();

        $this->assertSame('Camp', \App\Models\GlobalShowTemplate::where('token', 'camp26')->value('name'));
    }

    public function test_an_entitys_shows_are_private_to_its_followers_and_leaders_even_for_site_admins(): void
    {
        $entity = Entity::create(['name' => 'Local']);
        $show = \App\Models\Show::mainFor($entity);
        $member = User::factory()->create();
        $member->entities()->attach($entity->id, ['role' => 'viewer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $outsider = User::factory()->create();
        $query = ['entity_id' => $entity->id, 'show' => $show->token];

        // Guests are sent to log in, signed-in non-members are refused.
        $this->get(route('slides.index', $query))->assertRedirect(route('login'));
        $this->get(route('slides.archive', ['entity_id' => $entity->id]))->assertRedirect(route('login'));
        $this->get(route('slides.download-zip', $query))->assertRedirect(route('login'));
        $this->actingAs($outsider)->get(route('slides.index', $query))->assertForbidden();
        $this->actingAs($outsider)->get(route('slides.download-pptx', $query))->assertForbidden();

        $this->actingAs($member)->get(route('slides.index', $query))->assertOk();
        // Site admins subscribe like anyone else; unknown entity ids look the same as private ones.
        $this->actingAs($admin)->get(route('slides.index', $query))->assertForbidden();
        $this->actingAs($admin)->get(route('slides.index', ['entity_id' => 99999]))->assertForbidden();
        $admin->entities()->attach($entity->id, ['role' => 'viewer']);
        $this->actingAs($admin)->get(route('slides.index', $query))->assertOk();
    }

    public function test_global_views_stay_public_and_local_slide_downloads_need_membership(): void
    {
        $this->get(route('slides.index'))->assertOk();

        $entity = Entity::create(['name' => 'Local']);
        $slide = $this->slide(['entity_id' => $entity->id]);
        $member = User::factory()->create();
        $member->entities()->attach($entity->id, ['role' => 'viewer']);

        $this->get(route('slides.download', $slide->token))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('slides.download', $slide->token))->assertNotFound();
        // Past the visibility check (a slide with no media then 404s for lack of a file).
        $this->actingAs($member)->get(route('slides.download', $slide->token))->assertNotFound();
        $this->assertTrue(Slide::released()->visibleToUser($member)->whereKey($slide->id)->exists());
    }

    public function test_an_entity_can_close_subscriptions_leaving_only_leaders_and_kiosk_links(): void
    {
        $entity = Entity::create(['name' => 'Closed']);
        $this->assertTrue($entity->fresh()->allow_subscription);

        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $follower = User::factory()->create();
        $follower->entities()->attach($entity->id, ['role' => 'viewer']);
        $newcomer = User::factory()->create();
        $local = $this->slide(['entity_id' => $entity->id]);
        $query = ['entity_id' => $entity->id];

        // Open: followers see the feed and anyone can subscribe.
        $this->actingAs($follower)->get(route('slides.index', $query))->assertOk();
        $this->actingAs($newcomer)->post(route('entities.subscribe', $entity))->assertRedirect();
        $this->assertTrue($newcomer->entities()->whereKey($entity->id)->exists());
        $newcomer->entities()->detach($entity->id);

        // The leader closes it.
        $this->actingAs($follower)->patch(route('entity.slides.settings', $entity), ['allow_subscription' => false])->assertForbidden();
        $this->actingAs($leader)->patch(route('entity.slides.settings', $entity), ['allow_subscription' => false])->assertRedirect();
        $this->assertFalse($entity->fresh()->allow_subscription);

        $this->actingAs($follower)->get(route('slides.index', $query))->assertForbidden();
        $this->actingAs($follower)->get(route('slides.show', $local->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide', null));
        $this->actingAs($newcomer)->post(route('entities.subscribe', $entity))->assertForbidden();
        $this->assertFalse($newcomer->entities()->whereKey($entity->id)->exists());
        $this->assertSame([], $follower->viewableEntityIds());

        // Leaders keep access, and re-opening restores followers.
        $this->actingAs($leader)->get(route('slides.index', $query))->assertOk();
        $this->actingAs($leader)->patch(route('entity.slides.settings', $entity), ['allow_subscription' => true]);
        $this->actingAs($follower)->get(route('slides.index', $query))->assertOk();
    }

    public function test_an_entitys_archive_is_for_its_leaders_only(): void
    {
        $entity = Entity::create(['name' => 'Church']);
        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $follower = User::factory()->create();
        $follower->entities()->attach($entity->id, ['role' => 'viewer']);
        $siteAdmin = User::factory()->create(['role' => 'admin']);

        $expired = $this->slide(['entity_id' => $entity->id, 'expires_at' => now()->subDay(), 'title' => 'Old local']);
        $this->slide(['expires_at' => now()->subDay(), 'title' => 'Old global']);
        $query = ['entity_id' => $entity->id];

        $this->get(route('slides.archive', $query))->assertRedirect(route('login'));
        $this->actingAs($follower)->get(route('slides.archive', $query))->assertForbidden();
        $this->actingAs($siteAdmin)->get(route('slides.archive', $query))->assertForbidden();
        $this->actingAs($leader)->get(route('slides.archive', $query))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('slides.data', 2));

        // There is no global archive page.
        $this->get(route('slides.archive'))->assertNotFound();
        $this->actingAs($leader)->get(route('slides.archive'))->assertNotFound();

        // ...and an archived local slide's own link/download is leaders-only too.
        $this->actingAs($follower)->get(route('slides.show', $expired->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide', null));
        $this->actingAs($leader)->get(route('slides.show', $expired->token))
            ->assertInertia(fn (Assert $page) => $page->where('initialSlide.token', $expired->token));
        $this->actingAs($follower)->get(route('slides.download', $expired->token))->assertNotFound();
    }
}

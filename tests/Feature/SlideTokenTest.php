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
}

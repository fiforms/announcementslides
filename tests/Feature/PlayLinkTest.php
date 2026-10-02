<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\PlayLink;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlayLinkTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;
    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->entity = Entity::create(['name' => 'Test Church']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->entity->id, ['role' => 'admin']);
    }

    private function localSlide(Show $show, string $title = 'Local'): Slide
    {
        $slide = Slide::create([
            'title' => $title, 'status' => 'published', 'uploaded_by' => $this->leader->id,
            'entity_id' => $this->entity->id,
        ]);
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'a.jpg', 'original_filename' => 'a.jpg',
            'disk_path' => 'slides/a.jpg', 'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);
        $show->slides()->attach($slide->id, ['sort_order' => 1]);

        return $slide;
    }

    private function link(array $attrs = []): PlayLink
    {
        return PlayLink::create([
            'entity_id' => $this->entity->id, 'title' => 'Hallway TV', 'delay_seconds' => 20, ...$attrs,
        ]);
    }

    public function test_token_is_long_and_unique(): void
    {
        $a = $this->link();
        $b = $this->link();

        $this->assertSame(64, strlen($a->token));
        $this->assertNotSame($a->token, $b->token);
    }

    public function test_guest_plays_entity_slides_with_link_settings(): void
    {
        $this->localSlide($this->entity->mainShow());
        $link = $this->link();

        $this->get(route('play.show', $link->token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page
                ->component('Play/Show')
                ->where('delaySeconds', 20)
                ->has('slides', 1)
                ->where('slides.0.title', 'Local'));

        $this->assertNotNull($link->fresh()->last_used_at);
    }

    public function test_plays_the_chosen_show_not_main(): void
    {
        $this->localSlide($this->entity->mainShow(), 'Main');
        $extra = Show::create(['entity_id' => $this->entity->id, 'name' => 'Extra', 'is_main' => false]);
        $this->localSlide($extra, 'Extra slide');

        $this->getJson(route('play.slides', $this->link(['show_id' => $extra->id])->token))
            ->assertOk()
            ->assertJsonCount(1, 'slides')
            ->assertJsonPath('slides.0.title', 'Extra slide');
    }

    public function test_unknown_and_revoked_tokens_are_404(): void
    {
        $this->get(route('play.show', str_repeat('x', 64)))->assertNotFound();

        $link = $this->link(['revoked_at' => now()]);
        $this->get(route('play.show', $link->token))->assertNotFound();
        $this->getJson(route('play.slides', $link->token))->assertNotFound();
    }

    public function test_leader_creates_updates_and_revokes(): void
    {
        $q = ['entity_id' => $this->entity->id];

        $this->actingAs($this->leader)
            ->post(route('play-links.store', $q), ['title' => 'Aunt Betty', 'delay_seconds' => 15])
            ->assertRedirect();
        $link = PlayLink::firstWhere('title', 'Aunt Betty');
        $this->assertSame(15, $link->delay_seconds);

        $this->actingAs($this->leader)
            ->patch(route('play-links.update', ['playLink' => $link->id] + $q), ['title' => 'Betty', 'delay_seconds' => 30])
            ->assertRedirect();
        $this->assertSame(30, $link->fresh()->delay_seconds);

        $this->actingAs($this->leader)
            ->delete(route('play-links.destroy', ['playLink' => $link->id] + $q))
            ->assertRedirect();
        $this->get(route('play.show', $link->token))->assertNotFound();
    }

    public function test_title_is_required_and_show_must_belong_to_entity(): void
    {
        $other = Entity::create(['name' => 'Other']);
        $foreign = Show::create(['entity_id' => $other->id, 'name' => 'Theirs', 'is_main' => false]);
        $q = ['entity_id' => $this->entity->id];

        $this->actingAs($this->leader)
            ->post(route('play-links.store', $q), ['title' => '', 'delay_seconds' => 12])
            ->assertSessionHasErrors('title');
        $this->actingAs($this->leader)
            ->post(route('play-links.store', $q), ['title' => 'X', 'delay_seconds' => 12, 'show_id' => $foreign->id])
            ->assertSessionHasErrors('show_id');
    }

    public function test_non_leader_cannot_manage_links(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'viewer']);
        $link = $this->link();

        $this->actingAs($member)->get(route('play-links.index', ['entity_id' => $this->entity->id]))->assertForbidden();
        $this->actingAs($member)->delete(route('play-links.destroy', $link))->assertForbidden();
        $this->assertNull($link->fresh()->revoked_at);
    }
}

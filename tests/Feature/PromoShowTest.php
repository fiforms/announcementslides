<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PromoShowTest extends TestCase
{
    use RefreshDatabase;

    /** A promo distributed to two sites, each with its own copy holding $slide. */
    private function promo(): array
    {
        $uploader = User::factory()->create();
        $slide = Slide::create(['title' => 'Promo Video', 'status' => 'published', 'uploaded_by' => $uploader->id, 'fanout_sort_order' => -1]);
        SlideMedia::create(['slide_id' => $slide->id, 'media_type' => 'slide', 'filename' => 'promo.mp4', 'original_filename' => 'promo.mp4', 'file_size' => 10, 'disk_path' => 'slides/promo.mp4', 'mime_type' => 'video/mp4']);

        $template = GlobalShowTemplate::create(['name' => 'Camp Meeting']);
        $copies = collect(['Church A', 'Church B'])->map(function ($name) use ($template, $slide) {
            $entity = Entity::create(['name' => $name]);
            $show = Show::create(['entity_id' => $entity->id, 'global_template_id' => $template->id, 'name' => $template->name, 'auto_delete_when_empty' => true]);
            $show->slides()->attach($slide->id, ['sort_order' => -1, 'auto_added' => true]);

            return $show;
        });

        return [$template, $slide, $copies];
    }

    public function test_global_view_lists_and_plays_promo_by_template(): void
    {
        [$template, $slide, $copies] = $this->promo();

        // A leader's local slide in their own copy is not part of the promo.
        $local = Slide::create(['title' => 'Local', 'status' => 'published', 'entity_id' => $copies[0]->entity_id, 'uploaded_by' => User::factory()->create()->id]);
        SlideMedia::create(['slide_id' => $local->id, 'media_type' => 'slide', 'filename' => 'local.jpg', 'original_filename' => 'local.jpg', 'file_size' => 10, 'disk_path' => 'slides/local.jpg', 'mime_type' => 'image/jpeg']);
        $copies[0]->slides()->attach($local->id, ['sort_order' => 5]);

        $this->get(route('slides.index', ['promo' => $template->token]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('promoToken', $template->token)
                ->where('showToken', null)
                ->has('slides', 1)
                ->where('slides.0.id', $slide->id)
                ->where('availableShows.1.promo', $template->token)
                ->where('availableShows.1.name', 'Camp Meeting'));
    }

    public function test_promo_link_survives_losing_a_copy_and_falls_back_once_swept(): void
    {
        [$template, $slide, $copies] = $this->promo();
        $copies[0]->forceDelete();

        $this->get(route('slides.index', ['promo' => $template->token]))
            ->assertInertia(fn (Assert $page) => $page->where('slides.0.id', $slide->id));

        $template->delete();

        $this->get(route('slides.index', ['promo' => $template->token]))
            ->assertInertia(fn (Assert $page) => $page->where('promoToken', null)->where('showToken', Show::globalBoard()->token));
    }

    public function test_expired_promo_is_not_offered_in_dropdown(): void
    {
        [$template, $slide] = $this->promo();
        $slide->update(['expires_at' => now()->subMinute()]);

        $this->get(route('slides.index'))
            ->assertInertia(fn (Assert $page) => $page->has('availableShows', 1));
    }

    public function test_admin_can_list_rename_detach_and_delete(): void
    {
        [$template, $slide, $copies] = $this->promo();
        $copies[1]->update(['name' => 'Our Local Name']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.promo-shows.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PromoShows')
                ->where('promos.0.sites_count', 2)
                ->where('promos.0.slides.0.id', $slide->id)
                ->where('promos.0.slides.0.is_current', true));

        $this->actingAs($admin)->patch(route('admin.promo-shows.update', $template), ['name' => 'Camp Meeting 2026'])->assertRedirect();
        $this->assertSame('Camp Meeting 2026', $template->fresh()->name);
        $this->assertSame('Camp Meeting 2026', $copies[0]->fresh()->name);
        $this->assertSame('Our Local Name', $copies[1]->fresh()->name);

        $this->actingAs($admin)->delete(route('admin.promo-shows.slides.detach', [$template, $slide]))->assertRedirect();
        $this->assertSame(0, $copies[0]->slides()->count() + $copies[1]->slides()->count());
        $this->assertNotNull($slide->fresh());

        $this->actingAs($admin)->delete(route('admin.promo-shows.destroy', $template))->assertRedirect();
        $this->assertNull($template->fresh());
        $this->assertSame(0, Show::withTrashed()->whereIn('id', $copies->pluck('id'))->count());
    }

    public function test_non_admin_cannot_manage_promos(): void
    {
        [$template] = $this->promo();

        $this->actingAs(User::factory()->create())->get(route('admin.promo-shows.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->delete(route('admin.promo-shows.destroy', $template))->assertForbidden();
        $this->assertNotNull($template->fresh());
    }
}

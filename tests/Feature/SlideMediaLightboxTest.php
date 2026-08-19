<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SlideMediaLightboxTest extends TestCase
{
    use RefreshDatabase;

    private function makeSlideWithMedia(?int $entityId, int $uploadedBy): Slide
    {
        $slide = Slide::create([
            'title' => 'Test Slide',
            'status' => 'published',
            'uploaded_by' => $uploadedBy,
            'entity_id' => $entityId,
        ]);

        $slide->media()->create([
            'media_type' => 'slide',
            'filename' => 'a.jpg', 'original_filename' => 'a.jpg', 'disk_path' => 'slides/a.jpg',
            'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);
        $slide->media()->create([
            'media_type' => 'slide-overlay',
            'filename' => 'b.png', 'original_filename' => 'b.png', 'disk_path' => 'slides/b.png',
            'file_size' => 100, 'mime_type' => 'image/png',
        ]);

        return $slide;
    }

    public function test_show_editor_index_exposes_overlay_media_and_media_types(): void
    {
        $user = User::factory()->create();
        $entity = Entity::create(['name' => 'Entity A']);
        $user->entities()->attach($entity->id, ['role' => 'admin']);
        Show::mainFor($entity);

        $this->makeSlideWithMedia($entity->id, $user->id);

        $this->actingAs($user)
            ->get(route('shows.index', ['entity_id' => $entity->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shows/Manage')
                ->has('mediaTypes')
                ->has('unusedSlides.0.media', 2)
                ->where('unusedSlides.0.overlay_mime_type', 'image/png')
            );
    }

    public function test_media_download_succeeds_for_a_slides_own_current_media(): void
    {
        $user = User::factory()->create();
        $slide = $this->makeSlideWithMedia(null, $user->id);
        $media = $slide->media()->where('media_type', 'slide-overlay')->first();

        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put($media->disk_path, 'fake-bytes');

        $this->get(route('slides.media.download', [$slide->id, $media->id]))
            ->assertOk();
    }

    public function test_media_download_404s_when_media_does_not_belong_to_slide(): void
    {
        $user = User::factory()->create();
        $slideA = $this->makeSlideWithMedia(null, $user->id);
        $slideB = $this->makeSlideWithMedia(null, $user->id);
        $mediaFromB = $slideB->media()->where('media_type', 'slide-overlay')->first();

        $this->get(route('slides.media.download', [$slideA->id, $mediaFromB->id]))
            ->assertNotFound();
    }

    public function test_media_download_404s_for_a_non_current_slide(): void
    {
        $user = User::factory()->create();
        $slide = $this->makeSlideWithMedia(null, $user->id);
        $slide->update(['status' => 'draft']);
        $media = $slide->media()->where('media_type', 'slide')->first();

        $this->get(route('slides.media.download', [$slide->id, $media->id]))
            ->assertNotFound();
    }
}

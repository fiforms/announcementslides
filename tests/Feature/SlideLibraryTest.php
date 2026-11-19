<?php

namespace Tests\Feature;

use App\Jobs\GenerateLibraryThumbnail;
use App\Models\Entity;
use App\Models\LibraryItem;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Support\LibraryFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlideLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $leader;
    private Entity $church;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->church = Entity::create(['name' => 'Someville']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->church->id, ['role' => 'admin']);
    }

    /** A real image on the public disk, as the chunk endpoint would leave it. */
    private function assembledImage(string $color = 'red'): array
    {
        $img = imagecreatetruecolor(64, 36);
        imagefill($img, 0, 0, imagecolorallocate($img, $color === 'red' ? 255 : 0, 0, $color === 'red' ? 0 : 255));
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();

        $path = 'slides/' . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, $bytes);

        return ['disk_path' => $path, 'original_filename' => 'Mountain Lake.jpg', 'mime_type' => 'image/jpeg'];
    }

    private function addBackground(array $upload)
    {
        return $this->actingAs($this->admin)->post(route('admin.library.backgrounds.store'), ['upload' => $upload]);
    }

    private function readyBackground(): LibraryItem
    {
        $this->addBackground($this->assembledImage());
        $item = LibraryItem::backgrounds()->firstOrFail();
        Storage::disk('public')->put(LibraryFiles::thumbnailPath($item->sha256), 'thumb');
        $item->update(['thumbnail_path' => LibraryFiles::thumbnailPath($item->sha256)]);

        return $item;
    }

    private function readyTemplate(): LibraryItem
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"><text id="as-el-1" x="10" y="50">Welcome</text></svg>';
        Storage::disk('public')->put('library/templates/t.svg', $svg);

        return LibraryItem::create([
            'kind' => 'template', 'title' => 'Welcome', 'filename' => 't.svg', 'original_filename' => 'overlay.svg',
            'disk_path' => 'library/templates/t.svg', 'mime_type' => 'image/svg+xml', 'file_size' => strlen($svg),
            'overlay_settings' => ['widgets' => [['id' => 'w1', 'widget' => 'clock']]],
        ]);
    }

    public function test_a_background_is_stored_once_under_its_content_address(): void
    {
        $upload = $this->assembledImage();
        $this->addBackground($upload)->assertRedirect();

        $item = LibraryItem::backgrounds()->firstOrFail();
        $this->assertSame(hash('sha256', Storage::disk('public')->get($item->disk_path)), $item->sha256);
        $this->assertSame(LibraryFiles::backgroundPath($item->sha256, 'jpg'), $item->disk_path);
        $this->assertSame('Mountain Lake', $item->title);
        $this->assertSame([64, 36], [$item->image_width, $item->image_height]);
        Storage::disk('public')->assertMissing($upload['disk_path']);
        Queue::assertPushed(GenerateLibraryThumbnail::class);
    }

    public function test_uploading_identical_bytes_again_keeps_one_copy(): void
    {
        $first = $this->assembledImage();
        $this->addBackground($first);
        // Same bytes under a different temp name.
        $second = ['disk_path' => 'slides/' . Str::uuid() . '.jpg'] + $first;
        Storage::disk('public')->put($second['disk_path'], Storage::disk('public')->get(LibraryItem::first()->disk_path));

        $this->addBackground($second);

        $this->assertSame(1, LibraryItem::backgrounds()->count());
        Storage::disk('public')->assertMissing($second['disk_path']);
        $this->assertCount(1, Storage::disk('public')->allFiles('library/bg'));
    }

    public function test_only_admins_manage_the_library(): void
    {
        $this->actingAs($this->leader)->get(route('admin.library.index'))->assertForbidden();
        $this->actingAs($this->leader)->post(route('admin.library.templates.store'), ['title' => 'x'])->assertForbidden();
    }

    public function test_a_leader_builds_a_slide_sharing_the_background_and_copying_the_template(): void
    {
        $bg = $this->readyBackground();
        $tpl = $this->readyTemplate();

        $res = $this->actingAs($this->leader)->postJson(route('library.build'), [
            'title' => 'Welcome Sabbath', 'entity_id' => $this->church->id,
            'background_id' => $bg->id, 'template_id' => $tpl->id, 'add_to_show' => 'main',
        ])->assertOk();

        $slide = Slide::findOrFail($res->json('slide_id'));
        $this->assertSame($this->church->id, $slide->entity_id);
        $this->assertSame('published', $slide->status);

        $base = $slide->media()->where('media_type', 'slide')->firstOrFail();
        $this->assertSame($bg->disk_path, $base->disk_path);          // shared, not copied
        $this->assertSame($bg->thumbnail_path, $base->thumbnail_path);
        $this->assertCount(1, Storage::disk('public')->allFiles('library/bg'));

        $overlay = $slide->media()->where('media_type', 'slide-overlay')->firstOrFail();
        $this->assertNotSame($tpl->disk_path, $overlay->disk_path);   // its own copy
        $this->assertSame(Storage::disk('public')->get($tpl->disk_path), Storage::disk('public')->get($overlay->disk_path));
        $this->assertSame($tpl->overlay_settings, $overlay->overlay_settings); // widgets travel along

        $this->assertTrue($this->church->mainShow()->slides()->whereKey($slide->id)->exists());
    }

    public function test_many_slides_share_one_file_and_it_survives_until_none_use_it(): void
    {
        $bg = $this->readyBackground();
        $ids = [];
        foreach (range(1, 3) as $n) {
            $ids[] = $this->actingAs($this->leader)->postJson(route('library.build'), [
                'title' => "S{$n}", 'entity_id' => $this->church->id, 'background_id' => $bg->id,
            ])->assertOk()->json('slide_id');
        }
        $this->assertCount(1, Storage::disk('public')->allFiles('library/bg'));

        // Deleting a slide's media row never removes the shared file.
        $media = SlideMedia::where('slide_id', $ids[0])->firstOrFail();
        $media->deleteFiles();
        $media->delete();
        Storage::disk('public')->assertExists($bg->disk_path);

        // Library item deleted: still used by two slides, so the sweep keeps it.
        $bg->delete();
        (new LibraryFiles())->sweep(0);
        Storage::disk('public')->assertExists($bg->disk_path);
        Storage::disk('public')->assertExists($bg->thumbnail_path);

        // Once nothing uses it, the sweep removes it.
        SlideMedia::whereIn('slide_id', $ids)->delete();
        $deleted = (new LibraryFiles())->sweep(0);
        $this->assertContains("public:{$bg->disk_path}", $deleted);
        Storage::disk('public')->assertMissing($bg->disk_path);
        Storage::disk('public')->assertMissing($bg->thumbnail_path);
    }

    public function test_a_resized_slides_original_variant_keeps_the_shared_file_alive(): void
    {
        $bg = $this->readyBackground();
        $id = $this->actingAs($this->leader)->postJson(route('library.build'), [
            'title' => 'S', 'entity_id' => $this->church->id, 'background_id' => $bg->id,
        ])->json('slide_id');
        $media = SlideMedia::where('slide_id', $id)->firstOrFail();
        $media->forceFill(['disk_path' => 'slides/resized.jpg', 'variants' => ['original' => $media->currentVersion()]])->save();
        $bg->delete();

        (new LibraryFiles())->sweep(0);

        Storage::disk('public')->assertExists($bg->disk_path);
    }

    public function test_building_rules(): void
    {
        $bg = $this->readyBackground();
        $url = route('library.build');

        // Nothing picked.
        $this->actingAs($this->leader)->postJson($url, ['title' => 'x', 'entity_id' => $this->church->id])->assertStatus(422);
        // Another church's leader may not build for this one.
        $other = User::factory()->create();
        $this->actingAs($other)->postJson($url, ['title' => 'x', 'entity_id' => $this->church->id, 'background_id' => $bg->id])->assertForbidden();
        // Global slides are admin-only.
        $this->actingAs($this->leader)->postJson($url, ['title' => 'x', 'background_id' => $bg->id])->assertForbidden();
        $this->actingAs($this->admin)->postJson($url, ['title' => 'x', 'background_id' => $bg->id, 'add_to_show' => 'none'])->assertOk();
        // A background still being processed (no thumbnail yet) can't be used.
        $pending = LibraryItem::create(['kind' => 'background', 'title' => 'p', 'sha256' => str_repeat('a', 64), 'disk_path' => 'library/bg/aa/x.jpg', 'mime_type' => 'image/jpeg']);
        $this->actingAs($this->leader)->postJson($url, ['title' => 'x', 'entity_id' => $this->church->id, 'background_id' => $pending->id])->assertStatus(422);
    }

    public function test_catalog_lists_only_ready_items_for_people_who_manage_something(): void
    {
        $this->readyBackground();
        $this->readyTemplate();
        LibraryItem::create(['kind' => 'background', 'title' => 'pending', 'sha256' => str_repeat('b', 64), 'disk_path' => 'library/bg/bb/x.jpg', 'mime_type' => 'image/jpeg']);

        $res = $this->actingAs($this->leader)->getJson(route('library.catalog'))->assertOk();
        $this->assertCount(1, $res->json('backgrounds'));
        $this->assertCount(1, $res->json('templates'));

        $this->actingAs(User::factory()->create())->getJson(route('library.catalog'))->assertForbidden();
    }

    public function test_template_overlay_saves_through_the_editor_endpoint(): void
    {
        $this->actingAs($this->admin)->post(route('admin.library.templates.store'), ['title' => 'Branded'])->assertRedirect();
        $tpl = LibraryItem::templates()->firstOrFail();
        $this->assertNull($tpl->disk_path);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080"><g id="as-el-1"><text x="1" y="2">Hello</text></g></svg>';
        $source = ['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => [['id' => 'as-el-1', 'type' => 'text']]];
        $this->actingAs($this->admin)->putJson(route('admin.library.overlay.save', $tpl), ['svg' => $svg, 'source' => json_encode($source)])
            ->assertRedirect();

        $tpl->refresh();
        $this->assertStringStartsWith('library/templates/', $tpl->disk_path);
        $this->assertStringContainsString('as-overlay-source', Storage::disk('public')->get($tpl->disk_path));

        $this->actingAs($this->admin)->getJson(route('admin.library.overlay.show', $tpl))
            ->assertOk()->assertJsonPath('source.elements.0.id', 'as-el-1');
    }

    public function test_a_slide_built_on_a_library_video_loops_until_the_slide_delay(): void
    {
        $video = LibraryItem::create([
            'kind' => 'background', 'title' => 'Waterfall', 'sha256' => str_repeat('c', 64), 'filename' => 'w.mp4', 'original_filename' => 'w.mp4',
            'disk_path' => 'library/bg/cc/w.mp4', 'mime_type' => 'video/mp4', 'thumbnail_path' => 'library/thumbs/w.jpg',
        ]);
        $image = $this->readyBackground();

        $build = fn ($bg) => Slide::findOrFail($this->actingAs($this->leader)->postJson(route('library.build'), [
            'title' => 'S', 'entity_id' => $this->church->id, 'background_id' => $bg->id,
        ])->assertOk()->json('slide_id'));

        $this->assertSame('loop', $build($video)->video_playback_mode);
        $this->assertNotSame('loop', $build($image)->video_playback_mode);
    }

    private function plainSlide(?Entity $entity = null): Slide
    {
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => (($entity ? $this->leader : $this->admin))->id, 'entity_id' => $entity?->id]);
        Storage::disk('public')->put('slides/own.jpg', 'x');
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'own.jpg', 'original_filename' => 'own.jpg',
            'disk_path' => 'slides/own.jpg', 'file_size' => 1, 'mime_type' => 'image/jpeg',
        ]);

        return $slide;
    }

    public function test_the_slide_editor_can_swap_in_a_library_background_or_overlay(): void
    {
        $bg = $this->readyBackground();
        $tpl = $this->readyTemplate();
        $slide = $this->plainSlide($this->church);
        $url = route('local-slides.media.library', ['slide' => $slide->id, 'entity_id' => $this->church->id]);

        // Background replaces the slide's own picture; its uploaded file is freed.
        $this->actingAs($this->leader)->post($url, ['media_type' => 'slide', 'library_item_id' => $bg->id])->assertRedirect();
        $base = $slide->media()->where('media_type', 'slide')->get();
        $this->assertCount(1, $base);
        $this->assertSame($bg->disk_path, $base[0]->disk_path);
        Storage::disk('public')->assertMissing('slides/own.jpg');

        // Template becomes the overlay (and replaces a previous one).
        foreach ([1, 2] as $_) {
            $this->actingAs($this->leader)->post($url, ['media_type' => 'slide-overlay', 'library_item_id' => $tpl->id])->assertRedirect();
        }
        $overlays = $slide->media()->where('media_type', 'slide-overlay')->get();
        $this->assertCount(1, $overlays);
        $this->assertSame($tpl->overlay_settings, $overlays[0]->overlay_settings);
        $this->assertCount(1, Storage::disk('public')->allFiles('library/bg'));
    }

    public function test_adding_from_the_library_checks_kind_and_permission(): void
    {
        $bg = $this->readyBackground();
        $tpl = $this->readyTemplate();
        $slide = $this->plainSlide($this->church);
        $url = route('local-slides.media.library', ['slide' => $slide->id, 'entity_id' => $this->church->id]);

        // A template can't be used as the picture, nor a background as the overlay.
        $this->actingAs($this->leader)->post($url, ['media_type' => 'slide', 'library_item_id' => $tpl->id])->assertStatus(422);
        $this->actingAs($this->leader)->post($url, ['media_type' => 'slide-overlay', 'library_item_id' => $bg->id])->assertStatus(422);
        $this->actingAs($this->leader)->post($url, ['media_type' => 'color-flyer', 'library_item_id' => $bg->id])->assertSessionHasErrors('media_type');

        // Someone who doesn't manage the church can't.
        $this->actingAs(User::factory()->create())->post($url, ['media_type' => 'slide', 'library_item_id' => $bg->id])->assertForbidden();

        // The admin route works too.
        $this->actingAs($this->admin)->post(route('admin.slides.media.library', $slide), ['media_type' => 'slide', 'library_item_id' => $bg->id])->assertRedirect();
        $this->assertSame($bg->disk_path, $slide->media()->where('media_type', 'slide')->first()->disk_path);
    }

    public function test_a_show_background_can_come_from_the_library(): void
    {
        $bg = $this->readyBackground();
        $show = $this->church->mainShow();
        $show->update(['frame_youtube' => ['kind' => 'video', 'id' => 'abc', 'muted' => false]]);
        Storage::disk('public')->put('slides/own.jpg', 'x');
        $own = $show->media()->create([
            'media_type' => 'show-base', 'filename' => 'own.jpg', 'original_filename' => 'own.jpg',
            'disk_path' => 'slides/own.jpg', 'file_size' => 1, 'mime_type' => 'image/jpeg',
        ]);
        $url = route('shows.media.library', ['show' => $show->id, 'entity_id' => $this->church->id]);

        $this->actingAs($this->leader)->post($url, ['library_item_id' => $bg->id])->assertRedirect();

        $base = $show->media()->where('media_type', 'show-base')->get();
        $this->assertCount(1, $base);
        $this->assertSame($bg->disk_path, $base[0]->disk_path);          // shared file, not a copy
        $this->assertSame($bg->thumbnail_path, $base[0]->thumbnail_path);
        $this->assertNull($show->fresh()->frame_youtube);                 // replaces a YouTube background
        $this->assertNull(SlideMedia::find($own->id));
        Storage::disk('public')->assertMissing('slides/own.jpg');
        $this->assertCount(1, Storage::disk('public')->allFiles('library/bg'));
    }

    public function test_a_show_background_from_the_library_checks_kind_and_permission(): void
    {
        $bg = $this->readyBackground();
        $tpl = $this->readyTemplate();
        $show = $this->church->mainShow();
        $url = route('shows.media.library', ['show' => $show->id, 'entity_id' => $this->church->id]);

        $this->actingAs($this->leader)->post($url, ['library_item_id' => $tpl->id])->assertStatus(422);
        $this->actingAs(User::factory()->create())->post($url, ['library_item_id' => $bg->id])->assertForbidden();

        // Another church's leader can't set this show's background.
        $other = Entity::create(['name' => 'Elsewhere']);
        $stranger = User::factory()->create();
        $stranger->entities()->attach($other->id, ['role' => 'admin']);
        $this->actingAs($stranger)->post(route('shows.media.library', ['show' => $show->id, 'entity_id' => $other->id]), ['library_item_id' => $bg->id])
            ->assertNotFound();
        $this->assertSame(0, $show->media()->count());
    }
}

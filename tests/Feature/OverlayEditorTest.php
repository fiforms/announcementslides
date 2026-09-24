<?php

namespace Tests\Feature;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Entity;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OverlayEditorTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"><rect id="as-el-1" x="10" y="10" width="100" height="50" fill="#fff"/></svg>';

    private User $user;
    private Entity $entity;
    private Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $this->user = User::factory()->create();
        $this->entity = Entity::create(['name' => 'Entity A']);
        $this->user->entities()->attach($this->entity->id, ['role' => 'admin']);

        $this->slide = Slide::create([
            'title' => 'S', 'status' => 'published',
            'uploaded_by' => $this->user->id, 'entity_id' => $this->entity->id,
        ]);
        $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => 'a.jpg', 'original_filename' => 'a.jpg',
            'disk_path' => 'slides/a.jpg', 'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);
    }

    private function source(): string
    {
        return json_encode(['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => [['id' => 'as-el-1', 'type' => 'rect']]]);
    }

    private function save(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->put(
            route('local-slides.overlay.save', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id]),
            $data
        );
    }

    private function show(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->getJson(
            route('local-slides.overlay.show', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id])
        );
    }

    public function test_save_creates_a_single_editable_svg_overlay(): void
    {
        $this->save(['svg' => self::SVG, 'source' => $this->source()])->assertSessionHasNoErrors();

        $overlay = $this->slide->fresh()->overlayMedia;
        $this->assertSame('image/svg+xml', $overlay->mime_type);
        $contents = Storage::disk('public')->get($overlay->disk_path);
        $this->assertStringContainsString('as-overlay-source', $contents);
        $this->assertSame(strlen($contents), $overlay->file_size);
        Queue::assertPushed(SyncOverlayThumbnail::class);

        $this->show()
            ->assertOk()
            ->assertJsonPath('source.elements.0.id', 'as-el-1')
            ->assertJsonPath('overlay.mime_type', 'image/svg+xml');
    }

    public function test_save_replaces_the_previous_overlay(): void
    {
        Storage::disk('public')->put('slides/old.png', 'png');
        $this->slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'old.png', 'original_filename' => 'old.png',
            'disk_path' => 'slides/old.png', 'file_size' => 3, 'mime_type' => 'image/png',
        ]);

        $this->save(['svg' => self::SVG, 'source' => $this->source()])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->slide->media()->where('media_type', 'slide-overlay')->count());
        Storage::disk('public')->assertMissing('slides/old.png');
    }

    public function test_save_strips_dangerous_content_and_rejects_invalid_svg(): void
    {
        $dirty = str_replace('<rect', '<script>alert(1)</script><rect onload="alert(1)"', self::SVG);
        $this->save(['svg' => $dirty, 'source' => $this->source()])->assertSessionHasNoErrors();

        $contents = Storage::disk('public')->get($this->slide->fresh()->overlayMedia->disk_path);
        $this->assertStringNotContainsString('alert', $contents);

        $this->save(['svg' => '<svg', 'source' => $this->source()])->assertSessionHasErrors('svg');
        $this->save(['svg' => self::SVG, 'source' => '{"v":"x"}'])->assertSessionHasErrors('source');
    }

    public function test_tampered_overlay_is_offered_as_external(): void
    {
        $this->save(['svg' => self::SVG, 'source' => $this->source()]);
        $path = $this->slide->fresh()->overlayMedia->disk_path;
        $disk = Storage::disk('public');
        $disk->put($path, str_replace('width="100"', 'width="200"', $disk->get($path)));

        $this->show()
            ->assertOk()
            ->assertJsonPath('source', null)
            ->assertJsonPath('overlay.mime_type', 'image/svg+xml');
        $this->assertStringNotContainsString('as-overlay-source', $this->show()->json('overlay.svg'));
    }

    public function test_raster_overlay_is_returned_as_data_uri(): void
    {
        Storage::disk('public')->put('slides/o.png', 'PNGDATA');
        $this->slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'o.png', 'original_filename' => 'o.png',
            'disk_path' => 'slides/o.png', 'file_size' => 7, 'mime_type' => 'image/png',
        ]);

        $this->show()
            ->assertJsonPath('source', null)
            ->assertJsonPath('overlay.data_uri', 'data:image/png;base64,' . base64_encode('PNGDATA'));
    }

    public function test_no_overlay_returns_nulls(): void
    {
        $this->show()->assertOk()->assertExactJson(['source' => null, 'overlay' => null]);
    }

    public function test_other_users_cannot_access_the_overlay(): void
    {
        $other = User::factory()->create();
        $other->entities()->attach($this->entity->id, ['role' => 'admin']);
        $this->show($other)->assertForbidden();
        $this->save(['svg' => self::SVG, 'source' => $this->source()], $other)->assertForbidden();

        $outsider = User::factory()->create();
        $otherEntity = Entity::create(['name' => 'Entity B']);
        $outsider->entities()->attach($otherEntity->id, ['role' => 'admin']);
        $this->show($outsider)->assertForbidden();
    }

    public function test_uploaded_svg_is_sanitized_and_a_reupload_of_an_editor_file_stays_editable(): void
    {
        $disk = Storage::disk('public');
        $uuid = '11111111-2222-3333-4444-555555555555';
        $disk->put("slides/{$uuid}.svg", str_replace('<rect', '<script>alert(1)</script><rect', self::SVG));

        $this->actingAs($this->user)->post(
            route('local-slides.media.store', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id]),
            [
                'media_type' => 'slide-overlay', 'filename' => "{$uuid}.svg", 'disk_path' => "slides/{$uuid}.svg",
                'original_filename' => 'x.svg', 'file_size' => 999, 'mime_type' => 'image/svg+xml',
            ]
        )->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('script', $disk->get("slides/{$uuid}.svg"));
        $this->show()->assertJsonPath('source', null);

        // Download an editor-made file, then re-upload it unchanged.
        $this->save(['svg' => self::SVG, 'source' => $this->source()]);
        $editorFile = $disk->get($this->slide->fresh()->overlayMedia->disk_path);
        $this->slide->media()->where('media_type', 'slide-overlay')->delete();

        $uuid2 = '66666666-2222-3333-4444-555555555555';
        $disk->put("slides/{$uuid2}.svg", $editorFile);
        $this->actingAs($this->user)->post(
            route('local-slides.media.store', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id]),
            [
                'media_type' => 'slide-overlay', 'filename' => "{$uuid2}.svg", 'disk_path' => "slides/{$uuid2}.svg",
                'original_filename' => 'overlay.svg', 'file_size' => strlen($editorFile), 'mime_type' => 'image/svg+xml',
            ]
        )->assertSessionHasNoErrors();

        $this->show()->assertJsonPath('source.elements.0.id', 'as-el-1');
    }

    public function test_upload_of_non_svg_content_labelled_svg_is_rejected(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';
        Storage::disk('public')->put("slides/{$uuid}.svg", '<html><script>alert(1)</script></html>');

        $this->actingAs($this->user)->post(
            route('local-slides.media.store', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id]),
            [
                'media_type' => 'slide-overlay', 'filename' => "{$uuid}.svg", 'disk_path' => "slides/{$uuid}.svg",
                'original_filename' => 'x.svg', 'file_size' => 10, 'mime_type' => 'image/svg+xml',
            ]
        )->assertSessionHasErrors('file');

        Storage::disk('public')->assertMissing("slides/{$uuid}.svg");
        $this->assertNull($this->slide->fresh()->overlayMedia);
    }

    public function test_site_admin_can_save_and_load_an_overlay_from_the_admin_editor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->put(route('admin.slides.overlay.save', $this->slide), ['svg' => self::SVG, 'source' => $this->source()])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SyncOverlayThumbnail::class);
        $this->actingAs($admin)
            ->getJson(route('admin.slides.overlay.show', $this->slide))
            ->assertOk()
            ->assertJsonPath('source.elements.0.id', 'as-el-1');

        $this->actingAs($admin)
            ->get(route('admin.slides.edit', $this->slide))
            ->assertInertia(fn ($page) => $page
                ->where('slide.canonical_url', route('slides.show', $this->slide))
                ->where('slide.entity_id', $this->entity->id));
    }

    public function test_non_admins_cannot_use_the_admin_overlay_endpoints(): void
    {
        // $this->user leads the slide's entity, but isn't a site admin.
        $this->actingAs($this->user)
            ->getJson(route('admin.slides.overlay.show', $this->slide))
            ->assertForbidden();
        $this->actingAs($this->user)
            ->put(route('admin.slides.overlay.save', $this->slide), ['svg' => self::SVG, 'source' => $this->source()])
            ->assertForbidden();

        $this->assertNull($this->slide->fresh()->overlayMedia);
    }
}

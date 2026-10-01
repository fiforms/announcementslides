<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Support\UpscalerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResizeMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Slide $slide;
    private SlideMedia $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $this->admin->id]);
        $this->media = $this->makeMedia('11111111-1111-1111-1111-111111111111', 'png', 1280, 720);
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function makeMedia(string $uuid, string $ext, int $w, int $h): SlideMedia
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        $ext === 'png' ? imagepng($img) : imagejpeg($img);
        $bytes = ob_get_clean();
        Storage::disk('public')->put("slides/{$uuid}.{$ext}", $bytes);

        return $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => "{$uuid}.{$ext}", 'original_filename' => "poster.{$ext}",
            'disk_path' => "slides/{$uuid}.{$ext}", 'file_size' => strlen($bytes),
            'mime_type' => $ext === 'png' ? 'image/png' : 'image/jpeg',
            'image_width' => $w, 'image_height' => $h, 'thumbnail_path' => 'thumbs/orig.jpg',
        ]);
    }

    private function uploadUpscaled(int $w, int $h, string $uuid = '22222222-2222-2222-2222-222222222222', string $model = 'esrgan-medium')
    {
        $bytes = $this->jpeg($w, $h);
        Storage::disk('public')->put("slides/{$uuid}.jpg", $bytes);

        return $this->actingAs($this->admin)->post(
            route('admin.slides.media.resize', [$this->slide, $this->media]),
            [
                'filename' => "{$uuid}.jpg", 'disk_path' => "slides/{$uuid}.jpg", 'file_size' => strlen($bytes),
                'mime_type' => 'image/jpeg', 'kind' => 'upscale', 'model' => $model,
            ],
        );
    }

    public function test_upscaling_swaps_in_the_new_file_and_keeps_the_original(): void
    {
        $this->uploadUpscaled(2560, 1440)->assertSessionHasNoErrors();

        $m = $this->media->fresh();
        $this->assertSame('resized', $m->active_variant);
        $this->assertSame('slides/22222222-2222-2222-2222-222222222222.jpg', $m->disk_path);
        $this->assertSame('image/jpeg', $m->mime_type);
        $this->assertSame(2560, $m->image_width);
        $this->assertNull($m->thumbnail_path);
        $this->assertSame('slides/11111111-1111-1111-1111-111111111111.png', $m->variants['original']['disk_path']);
        $this->assertSame('esrgan-medium', $m->variants['resized']['model']);
        Storage::disk('public')->assertExists('slides/11111111-1111-1111-1111-111111111111.png');
        Queue::assertPushed(GenerateThumbnail::class);
    }

    public function test_an_upscale_that_is_not_double_the_size_is_rejected_and_cleaned_up(): void
    {
        $this->uploadUpscaled(1920, 1080)->assertSessionHasErrors('file');

        $this->assertNull($this->media->fresh()->active_variant);
        Storage::disk('public')->assertMissing('slides/22222222-2222-2222-2222-222222222222.jpg');
        Storage::disk('public')->assertExists('slides/11111111-1111-1111-1111-111111111111.png');
    }

    public function test_undo_and_redo_switch_versions_without_losing_either(): void
    {
        $this->uploadUpscaled(2560, 1440);
        $this->media->refresh()->update(['thumbnail_path' => 'thumbs/up.jpg']);

        $switch = fn (string $v) => $this->actingAs($this->admin)->post(
            route('admin.slides.media.version', [$this->slide, $this->media]), ['version' => $v]
        );

        $switch('original')->assertSessionHasNoErrors();
        $m = $this->media->fresh();
        $this->assertSame('original', $m->active_variant);
        $this->assertSame('slides/11111111-1111-1111-1111-111111111111.png', $m->disk_path);
        $this->assertSame(1280, $m->image_width);
        $this->assertSame('thumbs/orig.jpg', $m->thumbnail_path);
        $this->assertSame('thumbs/up.jpg', $m->variants['resized']['thumbnail_path']);

        $switch('resized')->assertSessionHasNoErrors();
        $m = $this->media->fresh();
        $this->assertSame('resized', $m->active_variant);
        $this->assertSame(2560, $m->image_width);
        $this->assertSame('thumbs/up.jpg', $m->thumbnail_path);
        $this->assertSame('esrgan-medium', $m->variants['resized']['model']);
        Queue::assertPushed(SyncOverlayThumbnail::class);
    }

    public function test_upscaling_again_after_an_undo_replaces_the_old_upscale(): void
    {
        $this->uploadUpscaled(2560, 1440);
        $this->actingAs($this->admin)->post(
            route('admin.slides.media.version', [$this->slide, $this->media]), ['version' => 'original']
        );

        $this->uploadUpscaled(2560, 1440, '33333333-3333-3333-3333-333333333333', 'esrgan-thick')->assertSessionHasNoErrors();

        $m = $this->media->fresh();
        $this->assertSame('slides/33333333-3333-3333-3333-333333333333.jpg', $m->disk_path);
        $this->assertSame('esrgan-thick', $m->variants['resized']['model']);
        Storage::disk('public')->assertMissing('slides/22222222-2222-2222-2222-222222222222.jpg');
    }

    public function test_an_already_upscaled_image_cannot_be_upscaled_again(): void
    {
        $this->uploadUpscaled(2560, 1440);
        $this->uploadUpscaled(5120, 2880, '44444444-4444-4444-4444-444444444444')->assertSessionHasErrors('file');

        $this->assertSame('slides/22222222-2222-2222-2222-222222222222.jpg', $this->media->fresh()->disk_path);
    }

    public function test_removing_the_media_deletes_both_versions(): void
    {
        $this->uploadUpscaled(2560, 1440);
        $second = $this->makeMedia('55555555-5555-5555-5555-555555555555', 'jpg', 1920, 1080);
        $second->update(['media_type' => 'slide']);

        $this->actingAs($this->admin)->delete(route('admin.slides.media.destroy', [$this->slide, $this->media]));

        Storage::disk('public')->assertMissing('slides/11111111-1111-1111-1111-111111111111.png');
        Storage::disk('public')->assertMissing('slides/22222222-2222-2222-2222-222222222222.jpg');
    }

    public function test_someone_else_cannot_upscale_a_contributors_slide(): void
    {
        $other = User::factory()->create(['role' => 'contributor']);
        $this->slide->update(['uploaded_by' => $this->admin->id]);
        $uuid = '66666666-6666-6666-6666-666666666666';
        Storage::disk('public')->put("slides/{$uuid}.jpg", $this->jpeg(2560, 1440));

        $this->actingAs($other)->post(
            route('my-slides.media.resize', [$this->slide, $this->media]),
            ['filename' => "{$uuid}.jpg", 'disk_path' => "slides/{$uuid}.jpg", 'file_size' => 10, 'mime_type' => 'image/jpeg', 'kind' => 'upscale', 'model' => 'esrgan-slim']
        )->assertForbidden();
    }

    public function test_videos_and_gifs_are_not_upscalable(): void
    {
        $this->media->update(['mime_type' => 'image/gif']);
        $this->assertFalse($this->media->fresh()->canBeResized());
        $this->media->update(['mime_type' => 'video/mp4']);
        $this->assertFalse($this->media->fresh()->canBeResized());
    }

    public function test_admin_can_save_upscaler_settings_and_others_cannot(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.upscaler.update'), [
            'enabled' => true, 'auto_on_upload' => false, 'model' => 'esrgan-slim', 'jpeg_quality' => 90, 'patch_size' => 32, 'downscale_oversized' => false,
        ])->assertSessionHasNoErrors();

        $s = UpscalerSettings::all();
        $this->assertSame('esrgan-slim', $s['model']);
        $this->assertFalse($s['auto_on_upload']);
        $this->assertFalse($s['downscale_oversized']);
        $this->assertSame(90, $s['jpeg_quality']);

        $this->actingAs($this->admin)->patch(route('admin.upscaler.update'), [
            'enabled' => true, 'auto_on_upload' => true, 'model' => 'bogus', 'jpeg_quality' => 90, 'patch_size' => 64, 'downscale_oversized' => true,
        ])->assertSessionHasErrors('model');

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->patch(route('admin.upscaler.update'), ['enabled' => false])->assertForbidden();
    }

    public function test_finalize_stores_an_upscaled_upload_with_its_original(): void
    {
        $orig = '77777777-7777-7777-7777-777777777777';
        $up   = '88888888-8888-8888-8888-888888888888';
        Storage::disk('public')->put("slides/{$orig}.png", 'x');
        $bytes = $this->jpeg(2560, 1440);
        Storage::disk('public')->put("slides/{$up}.jpg", $bytes);

        $this->actingAs($this->admin)->postJson(route('uploads.finalize'), [
            'title' => 'Poster',
            'add_to_show' => 'none',
            'uploads' => [[
                'filename' => "{$up}.jpg", 'disk_path' => "slides/{$up}.jpg", 'original_filename' => 'poster.jpg',
                'file_size' => strlen($bytes), 'mime_type' => 'image/jpeg',
                'resize' => ['kind' => 'upscale', 'model' => 'esrgan-medium', 'original' => [
                    'filename' => "{$orig}.png", 'disk_path' => "slides/{$orig}.png", 'original_filename' => 'poster.png',
                    'file_size' => 1, 'mime_type' => 'image/png',
                ]],
            ]],
        ])->assertOk();

        $m = SlideMedia::where('filename', "{$up}.jpg")->firstOrFail();
        $this->assertSame('resized', $m->active_variant);
        $this->assertSame("slides/{$orig}.png", $m->variants['original']['disk_path']);
        $this->assertSame('poster.png', $m->variants['original']['original_filename']);
        $this->assertSame(2560, $m->image_width);
    }

    private function uploadResized(string $kind, int $w, int $h, string $uuid = '99999999-9999-9999-9999-999999999999')
    {
        $bytes = $this->jpeg($w, $h);
        Storage::disk('public')->put("slides/{$uuid}.jpg", $bytes);

        return $this->actingAs($this->admin)->post(
            route('admin.slides.media.resize', [$this->slide, $this->media]),
            ['filename' => "{$uuid}.jpg", 'disk_path' => "slides/{$uuid}.jpg", 'file_size' => strlen($bytes), 'mime_type' => 'image/jpeg', 'kind' => $kind],
        );
    }

    public function test_an_image_larger_than_4k_can_be_downscaled_to_fit_and_undone(): void
    {
        $this->media->update(['image_width' => 7680, 'image_height' => 3840]);
        // 7680x3840 fits 3840x2160 at 0.5 → 3840x1920.
        $this->uploadResized('downscale', 3840, 1920)->assertSessionHasNoErrors();

        $m = $this->media->fresh();
        $this->assertSame('resized', $m->active_variant);
        $this->assertSame('downscale', $m->resizedKind());
        $this->assertNull($m->variants['resized']['model']);
        $this->assertSame('slides/11111111-1111-1111-1111-111111111111.png', $m->variants['original']['disk_path']);

        $this->actingAs($this->admin)->post(route('admin.slides.media.version', [$this->slide, $this->media]), ['version' => 'original']);
        $this->assertSame(7680, $this->media->fresh()->image_width);
    }

    public function test_downscaling_rejects_an_image_that_is_not_oversized_or_the_wrong_size(): void
    {
        // 1280x720 is not larger than 4K.
        $this->uploadResized('downscale', 640, 360)->assertSessionHasErrors('file');
        $this->assertNull($this->media->fresh()->active_variant);

        $this->media->update(['image_width' => 7680, 'image_height' => 3840]);
        $this->uploadResized('downscale', 3000, 1500, '99999999-9999-9999-9999-99999999999a')->assertSessionHasErrors('file');
    }

    public function test_image_resize_targets(): void
    {
        $this->assertSame([2560, 1440], \App\Support\ImageResize::target('upscale', 1280, 720));
        $this->assertSame([3840, 2160], \App\Support\ImageResize::target('downscale', 7680, 4320));
        $this->assertSame([3840, 1920], \App\Support\ImageResize::target('downscale', 5760, 2880));
        $this->assertSame([1620, 2160], \App\Support\ImageResize::target('downscale', 2700, 3600));
        $this->assertNull(\App\Support\ImageResize::target('downscale', 3840, 2160));
    }

    public function test_migration_renames_existing_upscaled_variants(): void
    {
        $this->media->forceFill([
            'active_variant' => 'upscaled',
            'variants' => ['original' => ['disk_path' => 'a'], 'upscaled' => ['disk_path' => 'b', 'upscale_model' => 'esrgan-slim']],
        ])->save();

        (include database_path('migrations/2026_10_02_000001_rename_upscaled_variant_to_resized.php'))->up();

        $m = $this->media->fresh();
        $this->assertSame('resized', $m->active_variant);
        $this->assertArrayNotHasKey('upscaled', $m->variants);
        $this->assertSame('upscale', $m->variants['resized']['kind']);
        $this->assertSame('esrgan-slim', $m->variants['resized']['model']);
        $this->assertArrayNotHasKey('upscale_model', $m->variants['resized']);
    }
}

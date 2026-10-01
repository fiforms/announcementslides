<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Services\VideoFrameExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        $this->slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);
    }

    private function media(string $type, string $filename, string $mime, string $bytes): SlideMedia
    {
        Storage::disk('public')->put("slides/{$filename}", $bytes);

        return $this->slide->media()->create([
            'media_type' => $type, 'filename' => $filename, 'original_filename' => $filename,
            'disk_path' => "slides/{$filename}", 'file_size' => strlen($bytes), 'mime_type' => $mime,
        ]);
    }

    private function generate(SlideMedia $media): void
    {
        (new GenerateThumbnail($media))->handle(app(VideoFrameExtractor::class));
    }

    public function test_an_uploaded_svg_overlay_refreshes_the_composite_without_crashing(): void
    {
        $media = $this->media('slide-overlay', 'overlay.svg', 'image/svg+xml',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"><rect width="10" height="10"/></svg>');

        $this->generate($media);

        $this->assertNull($media->fresh()->thumbnail_path);
        Queue::assertPushed(SyncOverlayThumbnail::class, fn ($job) => $job->slideId === $this->slide->id);
    }

    public function test_an_undecodable_image_is_skipped_instead_of_crashing(): void
    {
        $media = $this->media('color-flyer', 'broken.png', 'image/png', 'not really a png');

        $this->generate($media);

        $this->assertNull($media->fresh()->thumbnail_path);
    }

    public function test_raster_images_still_get_a_thumbnail(): void
    {
        $img = imagecreatetruecolor(1200, 675);
        ob_start();
        imagepng($img);
        $media = $this->media('slide', 'base.png', 'image/png', ob_get_clean());

        $this->generate($media);

        $path = $media->fresh()->thumbnail_path;
        $this->assertSame('thumbs/base.jpg', $path);
        $this->assertSame([600, 337], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));
        Queue::assertPushed(SyncOverlayThumbnail::class);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncOverlayThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private function putJpeg(string $relPath, int $width = 100, int $height = 60): void
    {
        $disk = Storage::disk('public');
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagejpeg($img);
        $disk->put($relPath, ob_get_clean());
        imagedestroy($img);
    }

    private function putPngWithAlpha(string $relPath, int $width = 40, int $height = 40): void
    {
        $disk = Storage::disk('public');
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 64);
        imagefilledrectangle($img, 0, 0, $width, $height, $transparent);
        ob_start();
        imagepng($img);
        $disk->put($relPath, ob_get_clean());
        imagedestroy($img);
    }

    public function test_composites_overlay_onto_primary_thumbnail(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        $this->putJpeg('thumbs/base.jpg');
        $primary = $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbs/base.jpg',
        ]);

        $this->putPngWithAlpha('slides/overlay.png');
        $slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'overlay.png', 'original_filename' => 'overlay.png',
            'disk_path' => 'slides/overlay.png', 'file_size' => 10, 'mime_type' => 'image/png',
        ]);

        (new SyncOverlayThumbnail($slide->id))->handle();

        $slide->refresh();
        $this->assertNotNull($slide->overlay_thumbnail_path);
        $this->assertTrue(Storage::disk('public')->exists($slide->overlay_thumbnail_path));
        $this->assertStringContainsString($slide->overlay_thumbnail_path, $slide->thumbnail_url);
    }

    public function test_falls_back_to_primary_thumbnail_when_no_overlay(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        $this->putJpeg('thumbs/base.jpg');
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbs/base.jpg',
        ]);

        (new SyncOverlayThumbnail($slide->id))->handle();

        $slide->refresh();
        $this->assertNull($slide->overlay_thumbnail_path);
        $this->assertStringContainsString('thumbs/base.jpg', $slide->thumbnail_url);
    }

    public function test_clears_composite_when_overlay_is_removed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        $this->putJpeg('thumbs/base.jpg');
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbs/base.jpg',
        ]);
        $this->putPngWithAlpha('slides/overlay.png');
        $overlay = $slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'overlay.png', 'original_filename' => 'overlay.png',
            'disk_path' => 'slides/overlay.png', 'file_size' => 10, 'mime_type' => 'image/png',
        ]);

        (new SyncOverlayThumbnail($slide->id))->handle();
        $this->assertNotNull($slide->refresh()->overlay_thumbnail_path);

        $overlay->delete();
        (new SyncOverlayThumbnail($slide->id))->handle();

        $this->assertNull($slide->refresh()->overlay_thumbnail_path);
    }
}

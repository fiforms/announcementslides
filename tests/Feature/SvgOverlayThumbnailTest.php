<?php

namespace Tests\Feature;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SvgOverlayThumbnailTest extends TestCase
{
    use RefreshDatabase;

    public function test_svg_overlay_gracefully_falls_back_when_rsvg_convert_is_unavailable(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        $img = imagecreatetruecolor(100, 60);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagejpeg($img);
        $disk->put('thumbs/base.jpg', ob_get_clean());
        imagedestroy($img);

        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbs/base.jpg',
        ]);

        $disk->put('slides/overlay.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"></svg>');
        $slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'overlay.svg', 'original_filename' => 'overlay.svg',
            'disk_path' => 'slides/overlay.svg', 'file_size' => 10, 'mime_type' => 'image/svg+xml',
        ]);

        config(['slides.rsvg_binary' => 'a-binary-that-definitely-does-not-exist-xyz']);

        (new SyncOverlayThumbnail($slide->id))->handle();

        $slide->refresh();
        $this->assertNull($slide->overlay_thumbnail_path);
        $this->assertStringContainsString('thumbs/base.jpg', $slide->thumbnail_url);
    }
}

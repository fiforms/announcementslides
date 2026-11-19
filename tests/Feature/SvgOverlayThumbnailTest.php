<?php

namespace Tests\Feature;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\User;
use App\Services\OverlayCompositor;
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

        (new SyncOverlayThumbnail($slide->id))->handle(new OverlayCompositor());

        $slide->refresh();
        $this->assertNull($slide->overlay_thumbnail_path);
        $this->assertStringContainsString('thumbs/base.jpg', $slide->thumbnail_url);
    }

    public function test_rasterized_overlay_uses_the_bundled_editor_fonts(): void
    {
        if (!trim((string) shell_exec('command -v ' . escapeshellarg(config('slides.rsvg_binary')) . ' 2>/dev/null'))) {
            $this->markTestSkipped('rsvg-convert is not installed.');
        }

        Storage::fake('public');
        $disk = Storage::disk('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);
        $compositor = new OverlayCompositor();

        $render = function (string $family) use ($slide, $disk, $compositor): string {
            $disk->put("slides/{$family}.svg", '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="100" viewBox="0 0 400 100">'
                . "<text x=\"10\" y=\"70\" font-size=\"60\" fill=\"#fff\" font-family=\"'{$family}', serif\">Welcome</text></svg>");
            $media = $slide->media()->create([
                'media_type' => 'slide-overlay', 'filename' => "{$family}.svg", 'original_filename' => "{$family}.svg",
                'disk_path' => "slides/{$family}.svg", 'file_size' => 10, 'mime_type' => 'image/svg+xml',
            ]);
            $dest = sys_get_temp_dir() . "/font-test-{$family}.jpg";
            $this->assertTrue($compositor->flatten(null, $media, $dest, 400, 100));
            $bytes = file_get_contents($dest);
            @unlink($dest);

            return $bytes;
        };

        // Italianno ships with the app; a made-up family falls back to serif.
        $this->assertNotSame($render('NoSuchFontXyz'), $render('Italianno'));
    }
}

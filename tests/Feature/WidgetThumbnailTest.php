<?php

namespace Tests\Feature;

use App\Jobs\SyncOverlayThumbnail;
use App\Models\Slide;
use App\Models\User;
use App\Models\Widget;
use App\Services\OverlayCompositor;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WidgetThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $disk = Storage::disk('public');

        $user = User::factory()->create();
        $this->slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        // Black 1920×1080 base thumbnail.
        $img = imagecreatetruecolor(1920, 1080);
        ob_start();
        imagejpeg($img);
        $disk->put('thumbs/base.jpg', ob_get_clean());
        $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            'thumbnail_path' => 'thumbs/base.jpg',
        ]);

        // Widget-only overlay: an empty SVG plus one placement in the
        // right half of the canvas.
        $disk->put('slides/overlay.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080"></svg>');
        $this->slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'overlay.svg', 'original_filename' => 'overlay.svg',
            'disk_path' => 'slides/overlay.svg', 'file_size' => 10, 'mime_type' => 'image/svg+xml',
            'overlay_settings' => ['widgets' => [[
                'id' => 'as-el-1', 'widget' => 'clock', 'x' => 960, 'y' => 0, 'w' => 960, 'h' => 1080, 'opacity' => 1, 'params' => [],
            ]]],
        ]);
    }

    private function sync(): void
    {
        (new SyncOverlayThumbnail($this->slide->id))->handle(new OverlayCompositor());
    }

    /** Brightness (0–255) of the composite at a point given in canvas units. */
    private function brightnessAt(int $x, int $y): int
    {
        $path = Storage::disk('public')->path($this->slide->fresh()->overlay_thumbnail_path);
        $img = imagecreatefromjpeg($path);
        $rgb = imagecolorat($img, (int) ($x * imagesx($img) / 1920), (int) ($y * imagesy($img) / 1080));

        return (int) ((($rgb >> 16 & 0xff) + ($rgb >> 8 & 0xff) + ($rgb & 0xff)) / 3);
    }

    public function test_the_composite_draws_the_widget_icon_in_its_box(): void
    {
        if (!is_executable(trim((string) shell_exec('command -v ' . escapeshellarg(config('slides.rsvg_binary')))))) {
            $this->markTestSkipped('rsvg-convert is not installed.');
        }
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));

        $this->sync();

        // The clock icon is centred in the right half (y≈810 is its white
        // face, below the hub); the left half stays the black base.
        $this->assertGreaterThan(200, $this->brightnessAt(1440, 810));
        $this->assertLessThan(30, $this->brightnessAt(480, 540));
    }

    public function test_a_widget_installed_after_the_list_was_first_read_is_still_drawn(): void
    {
        if (!is_executable(trim((string) shell_exec('command -v ' . escapeshellarg(config('slides.rsvg_binary')))))) {
            $this->markTestSkipped('rsvg-convert is not installed.');
        }

        // A long-running queue worker reads the list before the widget exists…
        $this->assertSame([], Widget::enabledBySlug());
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));

        // …and the next job, after the worker resets per-job state, must see it.
        app()->forgetScopedInstances();
        $this->sync();

        $this->assertGreaterThan(200, $this->brightnessAt(1440, 810));
    }
}

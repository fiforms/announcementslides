<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\User;
use App\Models\Widget;
use App\Services\RevelationSnapshotBuilder;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Slides with no image in the REVELation Snapshot export. */
class RevelationImagelessSlideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));
    }

    private function slide(string $title, bool $image, bool $overlay, array $widgets = []): Slide
    {
        $disk = Storage::disk('public');
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => User::factory()->create()->id]);
        if ($image) {
            $disk->put("slides/{$title}.jpg", 'jpeg-bytes');
            $slide->media()->create([
                'media_type' => 'slide', 'filename' => "{$title}.jpg", 'original_filename' => "{$title}.jpg",
                'disk_path' => "slides/{$title}.jpg", 'file_size' => 10, 'mime_type' => 'image/jpeg',
            ]);
        }
        if ($overlay) {
            $disk->put("slides/{$title}.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');
            $slide->media()->create([
                'media_type' => 'slide-overlay', 'filename' => "{$title}.svg", 'original_filename' => 'o.svg',
                'disk_path' => "slides/{$title}.svg", 'file_size' => 10, 'mime_type' => 'image/svg+xml',
                'overlay_settings' => $widgets ? ['widgets' => $widgets] : null,
            ]);
        }

        return $slide->load(['primaryMedia', 'overlayMedia']);
    }

    private function build(array $slides): array
    {
        $path = app(RevelationSnapshotBuilder::class)->build(collect($slides), 'Test');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $markdown = $zip->getFromName('presentation.md');
        $zip->close();
        @unlink($path);

        return [$names, $markdown];
    }

    public function test_an_overlay_only_slide_is_exported_with_its_widgets_and_an_empty_one_is_not(): void
    {
        $widget = ['id' => 'as-el-1', 'widget' => 'clock', 'x' => 960, 'y' => 540, 'w' => 480, 'h' => 270, 'opacity' => 1, 'params' => ['style' => 'digital']];
        $normal = $this->slide('a', image: true, overlay: false);
        $overlayOnly = $this->slide('b', image: false, overlay: true, widgets: [$widget]);
        $empty = $this->slide('c', image: false, overlay: false);

        [$names, $markdown] = $this->build([$normal, $overlayOnly, $empty]);

        $this->assertContains('001_a.jpg', $names);
        $this->assertCount(2, array_filter($names, fn ($n) => str_starts_with($n, '00') && ! str_ends_with($n, '.md')));
        // Slide 2 is its overlay alone (no background image) plus the widget block.
        [, $second] = explode("\n\n---\n\n", $markdown);
        $this->assertStringContainsString('![fill](002_overlay.svg)', $second);
        $this->assertStringNotContainsString('fill:background', $second);
        $this->assertStringContainsString(':widget:', $second);
        $this->assertStringContainsString('name: clock', $second);
    }

    public function test_a_show_of_only_empty_slides_has_nothing_to_export(): void
    {
        $this->expectException(RuntimeException::class);

        $this->build([$this->slide('c', image: false, overlay: false)]);
    }
}

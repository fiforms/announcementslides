<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Cover for SlideController::downloadPowerPoint(), which hand-builds a deck
 * against phpoffice/phppresentation — DocumentLayout, BackgroundColor,
 * DrawingFile and IOFactory. None of that was exercised by any test, which
 * made a major version bump of that library unverifiable. A .pptx is a zip
 * of XML parts, so the assertions below open it and look inside rather than
 * only checking the response code.
 */
class PowerPointExportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> real files written outside the database */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function makeSlideWithImage(string $title, int $w = 1920, int $h = 1080): Slide
    {
        $slide = Slide::create([
            'title'       => $title,
            'status'      => 'published',
            'uploaded_by' => User::factory()->create(['role' => 'admin'])->id,
            'entity_id'   => null,
        ]);

        // A real PNG on the real disk: DrawingFile reads the file off disk and
        // the writer embeds its bytes, so a faked disk will not do.
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 90));
        $rel = "slides/{$slide->id}.png";
        $abs = Storage::disk('public')->path($rel);
        @mkdir(dirname($abs), 0755, true);
        imagepng($im, $abs);
        imagedestroy($im);
        $this->written[] = $abs;

        $slide->media()->create([
            'media_type' => 'slide',
            'filename' => "{$slide->id}.png", 'original_filename' => "{$slide->id}.png",
            'disk_path' => $rel, 'file_size' => filesize($abs), 'mime_type' => 'image/png',
            'image_width' => $w, 'image_height' => $h,
        ]);

        return $slide;
    }

    private function openDeck(string $bytes): ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'deck') . '.pptx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'Export is not a readable zip archive');
        return $zip;
    }

    public function test_the_export_produces_a_readable_pptx(): void
    {
        $this->makeSlideWithImage('First');

        $response = $this->get('/slides/download-pptx');
        $response->assertOk();

        $zip = $this->openDeck($response->streamedContent() ?: $response->getContent());

        // The parts every PowerPoint2007 file must carry.
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('ppt/presentation.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
        $zip->close();
    }

    public function test_one_deck_slide_is_produced_per_announcement(): void
    {
        $this->makeSlideWithImage('One');
        $this->makeSlideWithImage('Two');
        $this->makeSlideWithImage('Three');

        $response = $this->get('/slides/download-pptx')->assertOk();
        $zip = $this->openDeck($response->streamedContent() ?: $response->getContent());

        $slides = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $zip->getNameIndex($i))) {
                $slides++;
            }
        }
        $zip->close();

        $this->assertSame(3, $slides, "Expected three deck slides, got {$slides}");
    }

    public function test_the_slide_images_are_embedded_in_the_deck(): void
    {
        $this->makeSlideWithImage('Embedded');

        $response = $this->get('/slides/download-pptx')->assertOk();
        $zip = $this->openDeck($response->streamedContent() ?: $response->getContent());

        $media = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'ppt/media/')) {
                $media++;
            }
        }
        $zip->close();

        $this->assertGreaterThan(0, $media, 'No image was embedded in the deck');
    }

    public function test_an_empty_selection_is_a_404_rather_than_an_empty_deck(): void
    {
        $this->get('/slides/download-pptx')->assertNotFound();
    }

    public function test_only_current_slides_reach_the_deck(): void
    {
        $this->makeSlideWithImage('Live');
        $this->makeSlideWithImage('Expired')->update(['expires_at' => now()->subDay()]);

        $response = $this->get('/slides/download-pptx')->assertOk();
        $zip = $this->openDeck($response->streamedContent() ?: $response->getContent());

        $slides = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $zip->getNameIndex($i))) {
                $slides++;
            }
        }
        $zip->close();

        $this->assertSame(1, $slides, 'An expired slide reached the deck');
    }
}

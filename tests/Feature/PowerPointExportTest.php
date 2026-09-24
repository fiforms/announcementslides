<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PowerPointExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSlide(bool $withOverlay): Slide
    {
        $disk = Storage::disk('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        $base = imagecreatetruecolor(1920, 1080);
        imagefill($base, 0, 0, imagecolorallocate($base, 0, 0, 255));
        ob_start();
        imagejpeg($base, null, 95);
        $disk->put('slides/base.jpg', ob_get_clean());
        imagedestroy($base);

        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
            'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
        ]);

        if ($withOverlay) {
            // Transparent overlay with an opaque red square in the middle.
            $overlay = imagecreatetruecolor(192, 108);
            imagealphablending($overlay, false);
            imagesavealpha($overlay, true);
            imagefilledrectangle($overlay, 0, 0, 191, 107, imagecolorallocatealpha($overlay, 0, 0, 0, 127));
            imagefilledrectangle($overlay, 76, 34, 115, 73, imagecolorallocatealpha($overlay, 255, 0, 0, 0));
            ob_start();
            imagepng($overlay);
            $disk->put('slides/overlay.png', ob_get_clean());
            imagedestroy($overlay);

            $slide->media()->create([
                'media_type' => 'slide-overlay', 'filename' => 'overlay.png', 'original_filename' => 'overlay.png',
                'disk_path' => 'slides/overlay.png', 'file_size' => 10, 'mime_type' => 'image/png',
            ]);
        }

        return $slide;
    }

    /** @return string[] raw bytes of each image embedded in the exported deck */
    private function exportedImages(Slide $slide): array
    {
        $response = $this->get(route('slides.download-pptx', ['ids' => $slide->id]));
        $response->assertOk();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $images = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'ppt/media/')) {
                $images[] = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $images;
    }

    public function test_overlay_is_burned_into_a_single_full_slide_image(): void
    {
        Storage::fake('public');
        $images = $this->exportedImages($this->makeSlide(withOverlay: true));

        $this->assertCount(1, $images);
        $img = imagecreatefromstring($images[0]);
        $this->assertSame([1920, 1080], [imagesx($img), imagesy($img)]);

        $center = imagecolorsforindex($img, imagecolorat($img, 960, 540));
        $this->assertGreaterThan(200, $center['red']);
        $this->assertLessThan(60, $center['blue']);

        $corner = imagecolorsforindex($img, imagecolorat($img, 10, 10));
        $this->assertGreaterThan(200, $corner['blue']);
        $this->assertLessThan(60, $corner['red']);
    }

    public function test_slide_without_overlay_exports_the_original_image(): void
    {
        Storage::fake('public');
        $images = $this->exportedImages($this->makeSlide(withOverlay: false));

        $this->assertCount(1, $images);
        $this->assertSame(Storage::disk('public')->get('slides/base.jpg'), $images[0]);
    }
}

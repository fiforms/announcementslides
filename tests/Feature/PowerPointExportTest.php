<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class PowerPointExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSlide(bool $withOverlay, bool $video = false): Slide
    {
        $disk = Storage::disk('public');
        $user = User::factory()->create();
        $slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id]);

        if ($video) {
            $this->putBlueVideo('slides/base.mp4');
            $this->putBlueJpeg('thumbs/base.jpg', 600, 338);
            $slide->media()->create([
                'media_type' => 'slide', 'filename' => 'base.mp4', 'original_filename' => 'base.mp4',
                'disk_path' => 'slides/base.mp4', 'file_size' => 10, 'mime_type' => 'video/mp4',
                'thumbnail_path' => 'thumbs/base.jpg',
            ]);
        } else {
            $this->putBlueJpeg('slides/base.jpg', 1920, 1080);
            $slide->media()->create([
                'media_type' => 'slide', 'filename' => 'base.jpg', 'original_filename' => 'base.jpg',
                'disk_path' => 'slides/base.jpg', 'file_size' => 10, 'mime_type' => 'image/jpeg',
            ]);
        }

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

    private function putBlueJpeg(string $relPath, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        Storage::disk('public')->put($relPath, ob_get_clean());
        imagedestroy($img);
    }

    private function putBlueVideo(string $relPath): void
    {
        $path = Storage::disk('public')->path($relPath);
        @mkdir(dirname($path), 0755, true);
        $process = new Process([
            config('slides.ffmpeg_binary'), '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=640x360:d=2',
            '-pix_fmt', 'yuv420p', $path,
        ]);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->markTestSkipped('ffmpeg is not available to build a test video.');
        }
    }

    private function assertBlueWithRedCenter(\GdImage $img): void
    {
        $center = imagecolorsforindex($img, imagecolorat($img, 960, 540));
        $this->assertGreaterThan(200, $center['red']);
        $this->assertLessThan(60, $center['blue']);

        $corner = imagecolorsforindex($img, imagecolorat($img, 10, 10));
        $this->assertGreaterThan(200, $corner['blue']);
        $this->assertLessThan(60, $corner['red']);
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
        $this->assertBlueWithRedCenter($img);
    }

    public function test_slide_without_overlay_exports_the_original_image(): void
    {
        Storage::fake('public');
        $images = $this->exportedImages($this->makeSlide(withOverlay: false));

        $this->assertCount(1, $images);
        $this->assertSame(Storage::disk('public')->get('slides/base.jpg'), $images[0]);
    }

    public function test_video_slide_exports_a_full_size_still_frame(): void
    {
        Storage::fake('public');
        $images = $this->exportedImages($this->makeSlide(withOverlay: false, video: true));

        $this->assertCount(1, $images);
        $img = imagecreatefromstring($images[0]);
        $this->assertSame([640, 360], [imagesx($img), imagesy($img)]);
        $pixel = imagecolorsforindex($img, imagecolorat($img, 320, 180));
        $this->assertGreaterThan(200, $pixel['blue']);
    }

    public function test_video_slide_overlay_is_burned_into_the_still_frame(): void
    {
        Storage::fake('public');
        $images = $this->exportedImages($this->makeSlide(withOverlay: true, video: true));

        $this->assertCount(1, $images);
        $img = imagecreatefromstring($images[0]);
        $this->assertSame([1920, 1080], [imagesx($img), imagesy($img)]);
        $this->assertBlueWithRedCenter($img);
    }

    public function test_video_slide_falls_back_to_thumbnail_when_ffmpeg_fails(): void
    {
        Storage::fake('public');
        $slide = $this->makeSlide(withOverlay: false, video: true);
        config(['slides.ffmpeg_binary' => 'a-binary-that-definitely-does-not-exist-xyz']);

        $images = $this->exportedImages($slide);

        $this->assertCount(1, $images);
        $this->assertSame(Storage::disk('public')->get('thumbs/base.jpg'), $images[0]);
    }
}

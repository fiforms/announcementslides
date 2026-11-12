<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/**
 * ZIP downloads leave out media over `slides.zip_max_media_bytes`, list it in
 * a note inside the archive, and never fetch an offloaded one to do so.
 */
class ZipDownloadSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('r2', ['url' => 'https://media.test']);
        $this->cacheDir = storage_path('framework/testing/media-cache-' . uniqid());
        config(['media.cache.path' => $this->cacheDir, 'slides.zip_max_media_bytes' => 1000]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    private function slide(string $title, string $file, int $bytes, string $mime, string $disk = 'public'): Slide
    {
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => $this->user->id]);
        Storage::disk($disk)->put("slides/{$file}", str_repeat('x', $bytes));
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => $file, 'original_filename' => $file,
            'disk_path' => "slides/{$file}", 'disk' => $disk, 'file_size' => $bytes, 'mime_type' => $mime,
        ]);

        return $slide;
    }

    /** @return array<string, string> entry name => contents */
    private function zipEntries(\Illuminate\Testing\TestResponse $response): array
    {
        $zip = new ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    public function test_media_over_the_cutoff_is_listed_instead_of_included(): void
    {
        $small = $this->slide('Potluck', 'aaaaaaaa-0000-0000-0000-000000000001.jpg', 500, 'image/jpeg');
        $video = $this->slide('Camp Meeting Promo', 'aaaaaaaa-0000-0000-0000-000000000002.mp4', 5000, 'video/mp4');

        $response = $this->get(route('slides.download-zip', ['ids' => "{$small->token},{$video->token}"]))->assertOk();
        $entries  = $this->zipEntries($response);

        $this->assertCount(2, $entries);
        $this->assertArrayHasKey('Large files not included.txt', $entries);
        $note = $entries['Large files not included.txt'];
        $this->assertStringContainsString('Camp Meeting Promo (4.9 KB)', $note);
        $this->assertStringContainsString(route('slides.show', $video->token), $note);
        $this->assertStringNotContainsString('Potluck', $note);
        $this->assertNotEmpty(array_filter(array_keys($entries), fn ($n) => str_contains($n, 'Potluck')));
    }

    public function test_an_offloaded_file_over_the_cutoff_is_never_fetched(): void
    {
        $video = $this->slide('Promo', 'aaaaaaaa-0000-0000-0000-000000000003.mp4', 5000, 'video/mp4', 'r2');

        $entries = $this->zipEntries($this->get(route('slides.download-zip', ['ids' => $video->token]))->assertOk());

        $this->assertSame(['Large files not included.txt'], array_keys($entries));
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/r2');
    }

    public function test_no_note_when_nothing_is_excluded_and_zero_means_no_limit(): void
    {
        $video = $this->slide('Promo', 'aaaaaaaa-0000-0000-0000-000000000004.mp4', 5000, 'video/mp4');
        config(['slides.zip_max_media_bytes' => 0]);

        $entries = $this->zipEntries($this->get(route('slides.download-zip', ['ids' => $video->token]))->assertOk());

        $this->assertCount(1, $entries);
        $this->assertArrayNotHasKey('Large files not included.txt', $entries);
    }

    public function test_the_slides_page_gets_the_cutoff_and_each_slides_file_size(): void
    {
        $slide = $this->slide('Promo', 'aaaaaaaa-0000-0000-0000-000000000005.mp4', 5000, 'video/mp4');

        // The deep link renders the same slide resource the grid uses.
        $this->get(route('slides.show', $slide->token))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Slides/Index')
                ->where('zipMaxMediaBytes', 1000)
                ->where('initialSlide.file_size', 5000)
            );
    }
}

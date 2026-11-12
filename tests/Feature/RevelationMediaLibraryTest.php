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
 * Large base media in a .revel download is linked through REVELation's media
 * library (a `media:` alias with `url_direct`) instead of being embedded.
 */
class RevelationMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('r2', ['url' => 'https://media.test']);
        $this->cacheDir = storage_path('framework/testing/media-cache-' . uniqid());
        config(['media.cache.path' => $this->cacheDir, 'slides.revelation_link_media_bytes' => 1000]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    private function slide(string $title, string $file, int $bytes, string $mime, string $disk = 'public', array $extra = []): Slide
    {
        $slide = Slide::create($extra + ['title' => $title, 'status' => 'published', 'uploaded_by' => $this->user->id]);
        Storage::disk($disk)->put("slides/{$file}", str_repeat('x', $bytes));
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => $file, 'original_filename' => $file,
            'disk_path' => "slides/{$file}", 'disk' => $disk, 'file_size' => $bytes, 'mime_type' => $mime,
        ]);

        return $slide;
    }

    /** @return array<string, string> entry name => contents */
    private function revel(array $slides): array
    {
        $tokens = implode(',', array_map(fn (Slide $s) => $s->token, $slides));
        $response = $this->get(route('slides.download-revelation', ['ids' => $tokens]))->assertOk();

        $zip = new ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    public function test_a_large_offloaded_video_is_linked_through_the_media_library(): void
    {
        $video = $this->slide('Camp Meeting', 'aaaaaaaa-0000-0000-0000-000000000001.mp4', 5000, 'video/mp4', 'r2',
            ['text_description' => "Join us: June 5-12"]);

        $entries = $this->revel([$video]);
        $md = $entries['presentation.md'];

        $this->assertStringContainsString(implode("\n", [
            'media:',
            '  slide_001:',
            '    filename: aaaaaaaa-0000-0000-0000-000000000001.mp4',
            '    title: Camp Meeting.mp4',
            '    mediatype: video',
            '    description: "Join us: June 5-12"',
            '    url_direct: "https://media.test/slides/aaaaaaaa-0000-0000-0000-000000000001.mp4"',
            '---',
        ]), $md);
        $this->assertStringContainsString('![fill](media:slide_001)', $md);

        // Not embedded, not listed, and never fetched from R2.
        $this->assertEmpty(array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.mp4')));
        $this->assertStringNotContainsString('.mp4"', $entries['manifest.json']);
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/r2');
    }

    public function test_small_media_is_still_embedded_and_a_local_large_file_links_to_this_host(): void
    {
        $image = $this->slide('Potluck', 'bbbbbbbb-0000-0000-0000-000000000001.jpg', 500, 'image/jpeg');
        $video = $this->slide('Promo', 'bbbbbbbb-0000-0000-0000-000000000002.mp4', 5000, 'video/mp4');

        $entries = $this->revel([$image, $video]);
        $md = $entries['presentation.md'];

        $this->assertNotEmpty(array_filter(array_keys($entries), fn ($n) => str_contains($n, 'Potluck')));
        $this->assertStringContainsString('url_direct: "' . Storage::disk('public')->url('slides/bbbbbbbb-0000-0000-0000-000000000002.mp4') . '"', $md);
        $this->assertStringNotContainsString('Potluck.jpg"', $md);
    }

    public function test_zero_means_always_embed(): void
    {
        config(['slides.revelation_link_media_bytes' => 0]);
        $video = $this->slide('Promo', 'cccccccc-0000-0000-0000-000000000001.mp4', 5000, 'video/mp4');

        $entries = $this->revel([$video]);

        $this->assertStringNotContainsString('media:', $entries['presentation.md']);
        $this->assertNotEmpty(array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.mp4')));
    }

    public function test_the_slides_page_gets_the_cutoff(): void
    {
        $slide = $this->slide('Promo', 'dddddddd-0000-0000-0000-000000000001.mp4', 5000, 'video/mp4');

        $this->get(route('slides.show', $slide->token))
            ->assertInertia(fn (Assert $page) => $page->where('revelationLinkMediaBytes', 1000));
    }
}

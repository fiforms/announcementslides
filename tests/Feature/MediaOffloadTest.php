<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Jobs\OffloadMedia;
use App\Jobs\OffloadRelease;
use App\Models\Slide;
use App\Models\SlideAnnouncerRelease;
use App\Models\SlideMedia;
use App\Models\User;
use App\Services\VideoFrameExtractor;
use App\Support\MediaCache;
use App\Support\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Storage tiers: large files move from the local `public` disk to the offload
 * disk (R2), small ones and SVGs never do, and everything that reads or
 * deletes media follows each row's `disk`.
 */
class MediaOffloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Slide $slide;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('r2', ['url' => 'https://media.test']);
        Storage::disk('r2')->buildTemporaryUrlsUsing(
            fn ($path, $expiration, $options) => "https://r2.test/{$path}?" . http_build_query($options)
        );

        $this->cacheDir = storage_path('framework/testing/media-cache-' . uniqid());
        config([
            'media.cache.path'  => $this->cacheDir,
            'media.offload_min_bytes' => 1000,
        ]);

        $this->user  = User::factory()->create();
        $this->slide = Slide::create(['title' => 'Camp Meeting', 'status' => 'published', 'uploaded_by' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    private function enable(): void
    {
        config(['media.offload_disk' => 'r2']);
    }

    private function jpegBytes(int $w = 1920, int $h = 1080): string
    {
        $img = imagecreatetruecolor($w, $h);
        // Noise, so the JPEG is well over the (test) size threshold.
        for ($i = 0; $i < 4000; $i++) {
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($img, null, 95);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function media(string $uuid, string $mime, string $bytes, array $extra = []): SlideMedia
    {
        $ext = ['image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'video/mp4' => 'mp4'][$mime];
        Storage::disk('public')->put("slides/{$uuid}.{$ext}", $bytes);

        return $this->slide->media()->create($extra + [
            'media_type' => 'slide', 'filename' => "{$uuid}.{$ext}", 'original_filename' => "file.{$ext}",
            'disk_path' => "slides/{$uuid}.{$ext}", 'file_size' => strlen($bytes), 'mime_type' => $mime,
        ]);
    }

    /** A VideoFrameExtractor stand-in that writes a JPEG and counts calls. */
    private function fakeFrames(bool $succeeds = true): object
    {
        $fake = new class($succeeds, $this->jpegBytes(640, 360)) extends VideoFrameExtractor {
            public int $calls = 0;

            public function __construct(private bool $succeeds, private string $jpeg) {}

            public function extract(string $source, string $dest, ?int $width = null, array $logContext = []): bool
            {
                $this->calls++;
                if (!$this->succeeds) {
                    return false;
                }
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, $this->jpeg);

                return true;
            }
        };
        $this->app->instance(VideoFrameExtractor::class, $fake);

        return $fake;
    }

    // ── Offloading ───────────────────────────────────────────────────────────

    public function test_with_offloading_off_everything_stays_local(): void
    {
        $media = $this->media('aaaaaaaa-0000-0000-0000-000000000001', 'image/jpeg', $this->jpegBytes());

        GenerateThumbnail::dispatchSync($media);

        $media->refresh();
        $this->assertSame('public', $media->disk);
        Storage::disk('public')->assertExists($media->disk_path);
        Storage::disk('r2')->assertMissing($media->disk_path);
        $this->assertNotNull($media->thumbnail_path);
    }

    public function test_a_large_image_moves_to_r2_after_its_thumbnail_is_made(): void
    {
        $this->enable();
        $media = $this->media('aaaaaaaa-0000-0000-0000-000000000002', 'image/jpeg', $this->jpegBytes());

        GenerateThumbnail::dispatchSync($media);

        $media->refresh();
        $this->assertSame('r2', $media->disk);
        Storage::disk('r2')->assertExists($media->disk_path);
        Storage::disk('public')->assertMissing($media->disk_path);
        Storage::disk('public')->assertExists($media->thumbnail_path);
        $this->assertStringStartsWith('https://media.test/', $media->file_url);
        $this->assertStringNotContainsString('media.test', $media->thumbnail_url);
    }

    public function test_svgs_and_small_files_are_never_offloaded(): void
    {
        $this->enable();
        config(['media.offload_min_bytes' => 5000]);
        $svg   = $this->media('aaaaaaaa-0000-0000-0000-000000000003', 'image/svg+xml',
            '<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080">' . str_repeat('<rect width="1" height="1"/>', 500) . '</svg>');
        $small = $this->media('aaaaaaaa-0000-0000-0000-000000000004', 'image/jpeg', $this->jpegBytes(16, 9));
        $this->assertGreaterThan(5000, $svg->file_size);
        $this->assertLessThan(5000, $small->file_size);

        OffloadMedia::dispatchSync($svg);
        OffloadMedia::dispatchSync($small);

        $this->assertSame('public', $svg->fresh()->disk);
        $this->assertSame('public', $small->fresh()->disk);
        Storage::disk('r2')->assertMissing([$svg->disk_path, $small->disk_path]);
    }

    public function test_a_file_another_row_also_uses_is_left_in_place(): void
    {
        $this->enable();
        $media = $this->media('aaaaaaaa-0000-0000-0000-000000000005', 'image/jpeg', $this->jpegBytes());
        $this->slide->media()->create(['media_type' => 'color-flyer'] + collect($media->only([
            'filename', 'original_filename', 'disk_path', 'file_size', 'mime_type',
        ]))->all());

        OffloadMedia::dispatchSync($media);

        $this->assertSame('public', $media->fresh()->disk);
        Storage::disk('public')->assertExists($media->disk_path);
    }

    // ── Video posters ────────────────────────────────────────────────────────

    public function test_a_video_gets_a_poster_once_and_rebuilding_its_thumbnail_never_needs_the_video(): void
    {
        $this->enable();
        $frames = $this->fakeFrames();
        $media  = $this->media('bbbbbbbb-0000-0000-0000-000000000001', 'video/mp4', str_repeat('v', 5000));

        GenerateThumbnail::dispatchSync($media);

        $media->refresh();
        $this->assertSame(1, $frames->calls);
        $this->assertNotNull($media->poster_path);
        $this->assertNotNull($media->thumbnail_path);
        Storage::disk('public')->assertExists([$media->poster_path, $media->thumbnail_path]);
        $this->assertSame('r2', $media->disk);
        Storage::disk('public')->assertMissing($media->disk_path);

        // Rebuild: made from the poster, no ffmpeg and nothing pulled from R2.
        Storage::disk('public')->delete($media->thumbnail_path);
        GenerateThumbnail::dispatchSync($media);

        $this->assertSame(1, $frames->calls);
        Storage::disk('public')->assertExists($media->fresh()->thumbnail_path);
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/r2');
    }

    public function test_a_video_whose_poster_cannot_be_made_stays_local(): void
    {
        $this->enable();
        $this->fakeFrames(succeeds: false);
        $media = $this->media('bbbbbbbb-0000-0000-0000-000000000002', 'video/mp4', str_repeat('v', 5000));

        OffloadMedia::dispatchSync($media);

        $this->assertSame('public', $media->fresh()->disk);
        Storage::disk('public')->assertExists($media->disk_path);
    }

    public function test_powerpoint_export_uses_the_poster_without_running_ffmpeg(): void
    {
        $this->enable();
        $frames = $this->fakeFrames();
        $media  = $this->media('bbbbbbbb-0000-0000-0000-000000000003', 'video/mp4', str_repeat('v', 5000));
        GenerateThumbnail::dispatchSync($media);
        $this->assertSame('r2', $media->fresh()->disk);

        $this->get(route('slides.download-pptx', ['ids' => $this->slide->token]))->assertOk();

        $this->assertSame(1, $frames->calls);
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/r2');
    }

    // ── Reading, downloading, deleting ───────────────────────────────────────

    public function test_downloading_an_offloaded_file_redirects_to_a_signed_url_with_its_name(): void
    {
        $this->enable();
        $media = $this->media('cccccccc-0000-0000-0000-000000000001', 'image/jpeg', $this->jpegBytes());
        OffloadMedia::dispatchSync($media);

        $response = $this->get(route('slides.media.download', [$this->slide->token, $media->id]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://r2.test/' . $media->disk_path, $location);
        $this->assertStringContainsString('attachment; filename="Camp Meeting.jpg"', urldecode($location));
    }

    public function test_downloading_a_local_file_still_streams_it(): void
    {
        $media = $this->media('cccccccc-0000-0000-0000-000000000002', 'image/jpeg', $this->jpegBytes());

        $this->get(route('slides.media.download', [$this->slide->token, $media->id]))
            ->assertOk()
            ->assertDownload('Camp Meeting.jpg');
    }

    public function test_zip_export_includes_an_offloaded_file_via_the_cache(): void
    {
        $this->enable();
        $media = $this->media('cccccccc-0000-0000-0000-000000000003', 'image/jpeg', $bytes = $this->jpegBytes());
        OffloadMedia::dispatchSync($media);

        $response = $this->get(route('slides.download-zip', ['ids' => $this->slide->token]))->assertOk();

        $zip = new \ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame($bytes, $zip->getFromIndex(0));
        $zip->close();
        $this->assertFileExists($this->cacheDir . '/r2/' . $media->disk_path);
    }

    public function test_undo_after_a_resize_follows_each_versions_disk_and_delete_removes_both(): void
    {
        $this->enable();
        $media = $this->media('dddddddd-0000-0000-0000-000000000001', 'image/jpeg', $this->jpegBytes());
        OffloadMedia::dispatchSync($media);
        $media->refresh();
        $this->assertSame('r2', $media->disk);

        // A resized version arrives (local), as resizeMediaForSlide stores it.
        $resizedBytes = $this->jpegBytes(3840, 2160);
        Storage::disk('public')->put('slides/dddddddd-0000-0000-0000-000000000002.jpg', $resizedBytes);
        $media->adoptResized($media->currentVersion(), [
            'filename' => 'dddddddd-0000-0000-0000-000000000002.jpg', 'original_filename' => 'file.jpg',
            'disk_path' => 'slides/dddddddd-0000-0000-0000-000000000002.jpg', 'disk' => 'public',
            'file_size' => strlen($resizedBytes), 'mime_type' => 'image/jpeg', 'thumbnail_path' => null,
            'poster_path' => null, 'image_width' => 3840, 'image_height' => 2160,
            'validation_issues' => [], 'validation_status' => 'ok',
        ], 'upscale');
        $this->assertSame('public', $media->fresh()->disk);

        $media->switchToVariant('original');
        $this->assertSame('r2', $media->fresh()->disk);
        $this->assertStringStartsWith('https://media.test/', $media->fresh()->file_url);

        $media->fresh()->deleteFiles();
        Storage::disk('r2')->assertMissing('slides/dddddddd-0000-0000-0000-000000000001.jpg');
        Storage::disk('public')->assertMissing('slides/dddddddd-0000-0000-0000-000000000002.jpg');
    }

    public function test_a_snapshot_from_before_storage_tiers_switches_back_as_local(): void
    {
        $media = $this->media('dddddddd-0000-0000-0000-000000000003', 'image/jpeg', $this->jpegBytes());
        $media->forceFill([
            'disk' => 'r2',
            'variants' => [
                'original' => ['disk_path' => 'slides/old.jpg', 'filename' => 'old.jpg', 'mime_type' => 'image/jpeg'],
                'resized'  => ['disk_path' => $media->disk_path, 'kind' => 'upscale'],
            ],
            'active_variant' => 'resized',
        ])->save();

        $media->switchToVariant('original');

        $this->assertSame('public', $media->fresh()->disk);
    }

    // ── Cache ────────────────────────────────────────────────────────────────

    public function test_the_cache_fetches_once_and_evicts_least_recently_used_files_but_not_recent_ones(): void
    {
        Storage::disk('r2')->put('slides/a.bin', str_repeat('a', 600));
        Storage::disk('r2')->put('slides/b.bin', str_repeat('b', 600));
        $cache = app(MediaCache::class);
        config(['media.cache.max_bytes' => 1000, 'media.cache.min_age_minutes' => 60]);

        $a = $cache->get('r2', 'slides/a.bin');
        $this->assertSame(str_repeat('a', 600), file_get_contents($a));

        // A hit is served from the cache, even if the object is gone.
        Storage::disk('r2')->delete('slides/a.bin');
        $this->assertSame($a, $cache->get('r2', 'slides/a.bin'));

        // Over target, but both were just used: nothing is evicted.
        $b = $cache->get('r2', 'slides/b.bin');
        $this->assertFileExists($a);
        $this->assertFileExists($b);

        // Once 'a' has gone unused past the minimum age, it goes first.
        touch($a, time() - 7200);
        $result = $cache->prune();
        $this->assertSame(1, $result['evicted']);
        $this->assertFileDoesNotExist($a);
        $this->assertFileExists($b);

        $cache->prune(all: true);
        $this->assertFileDoesNotExist($b);
    }

    public function test_the_cache_refuses_path_traversal(): void
    {
        $this->expectException(\RuntimeException::class);
        app(MediaCache::class)->pathFor('r2', '../../.env');
    }

    // ── Releases and switching back ──────────────────────────────────────────

    public function test_a_release_moves_to_r2_under_a_content_tagged_key(): void
    {
        $this->enable();
        Storage::disk('public')->put('slide-announcer/releases/app/arm64/0.4.1_0.1.0.tar.gz', 'bundle-bytes');
        $release = SlideAnnouncerRelease::create([
            'kind' => 'app', 'version' => '0.4.1_0.1.0', 'architecture' => 'arm64', 'release_type' => 'full',
            'disk_path' => 'slide-announcer/releases/app/arm64/0.4.1_0.1.0.tar.gz', 'sha256' => hash('sha256', 'bundle-bytes'),
        ]);

        OffloadRelease::dispatchSync($release);

        $release->refresh();
        $key = 'slide-announcer/releases/app/arm64/0.4.1_0.1.0-' . substr($release->sha256, 0, 12) . '.tar.gz';
        $this->assertSame('r2', $release->disk);
        $this->assertSame($key, $release->disk_path);
        Storage::disk('r2')->assertExists($key);
        Storage::disk('public')->assertMissing('slide-announcer/releases/app/arm64/0.4.1_0.1.0.tar.gz');
        $this->assertSame("https://media.test/{$key}", $release->url());
        $this->assertSame(strlen('bundle-bytes'), $release->fileSize());
    }

    private function taggedRelease(string $kind, string $version, string $type = 'full'): SlideAnnouncerRelease
    {
        $path = "slide-announcer/releases/{$kind}/arm64/{$version}." . ($kind === 'app' ? 'tar.gz' : 'raucb');
        Storage::disk('public')->put($path, "{$kind}-{$version}-bytes");
        $release = SlideAnnouncerRelease::create([
            'kind' => $kind, 'version' => $version, 'architecture' => 'arm64', 'release_type' => $type,
            'disk_path' => $path, 'sha256' => hash('sha256', "{$kind}-{$version}-bytes"),
        ]);
        $release->tagChannel('stable');

        return $release;
    }

    private function heartbeat(): \Illuminate\Testing\TestResponse
    {
        $device = \App\Models\SlideAnnouncer::firstOrCreate(
            ['name' => 'Lobby'],
            ['entity_id' => \App\Models\Entity::create(['name' => 'Church'])->id],
        );
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->createToken('d')->plainTextToken)
            ->postJson('/api/slide-announcers/heartbeat', ['app_version' => '0.4.0_0.1.0', 'os_version' => '0.4.0_0.1.0', 'architecture' => 'arm64'])
            ->assertOk();
    }

    public function test_devices_are_not_offered_a_release_until_it_is_on_r2(): void
    {
        $this->enable();
        $app = $this->taggedRelease('app', '0.4.1_0.1.0');
        $os  = $this->taggedRelease('os', '0.4.1_0.1.0');

        $this->heartbeat()
            ->assertJsonPath('app_update_available', false)->assertJsonPath('app_download_url', null)
            ->assertJsonPath('os_update_available', false)->assertJsonPath('os_bundle_url', null);

        OffloadRelease::dispatchSync($app);
        OffloadRelease::dispatchSync($os);

        $response = $this->heartbeat()
            ->assertJsonPath('app_update_available', true)
            ->assertJsonPath('os_update_available', true);
        $this->assertStringStartsWith('https://media.test/', $response->json('app_download_url'));
        $this->assertStringStartsWith('https://media.test/', $response->json('os_bundle_url'));
    }

    public function test_releases_are_offered_from_this_host_when_offloading_is_off_or_waiting_is_disabled(): void
    {
        $this->taggedRelease('app', '0.4.1_0.1.0');
        $this->heartbeat()->assertJsonPath('app_update_available', true);

        $this->enable();
        config(['media.releases_wait_for_offload' => false]);
        $this->heartbeat()->assertJsonPath('app_update_available', true);

        config(['media.releases_wait_for_offload' => true, 'media.offload_releases' => false]);
        $this->heartbeat()->assertJsonPath('app_update_available', true);
    }

    public function test_a_disk_image_awaiting_offload_has_no_download_link_yet(): void
    {
        $this->enable();
        $image = $this->taggedRelease('os', '0.4.1_0.1.0', 'disk_image');
        $this->assertTrue($image->awaitingOffload());

        OffloadRelease::dispatchSync($image);

        $this->assertFalse($image->fresh()->awaitingOffload());
    }

    public function test_media_localize_brings_offloaded_files_back(): void
    {
        $this->enable();
        $media = $this->media('eeeeeeee-0000-0000-0000-000000000001', 'image/jpeg', $bytes = $this->jpegBytes());
        OffloadMedia::dispatchSync($media);
        $this->assertSame('r2', $media->fresh()->disk);

        config(['media.offload_disk' => null]);
        $this->artisan('media:localize')->assertSuccessful();

        $media->refresh();
        $this->assertSame('public', $media->disk);
        $this->assertSame($bytes, Storage::disk('public')->get($media->disk_path));
        Storage::disk('r2')->assertMissing($media->disk_path);
    }

    public function test_media_offload_backfills_existing_files(): void
    {
        $media = $this->media('eeeeeeee-0000-0000-0000-000000000002', 'image/jpeg', $this->jpegBytes());

        $this->artisan('media:offload')->assertFailed(); // off: refuses

        $this->enable();
        $this->artisan('media:offload --dry-run')->assertSuccessful();
        $this->assertSame('public', $media->fresh()->disk);

        $this->artisan('media:offload --now')->assertSuccessful();
        $this->assertSame('r2', $media->fresh()->disk);
    }

    public function test_offload_eligibility(): void
    {
        $store = app(MediaStore::class);
        $this->assertFalse($store->eligible('video/mp4', 10_000_000)); // off

        $this->enable();
        $this->assertTrue($store->eligible('video/mp4', 10_000_000));
        $this->assertTrue($store->eligible('application/pdf', 10_000_000));
        $this->assertFalse($store->eligible('image/svg+xml', 10_000_000));
        $this->assertFalse($store->eligible('text/html', 10_000_000));
        $this->assertFalse($store->eligible('image/jpeg', 10));
    }
}

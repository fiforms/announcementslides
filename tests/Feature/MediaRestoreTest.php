<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\SlideAnnouncerRelease;
use App\Models\SlideMedia;
use App\Models\User;
use App\Support\MediaInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The restore path for offloaded media: deleted objects go to the bucket
 * trash, media:verify finds (and recovers) missing files and checks a bucket
 * mirror, media:localize --relink points rows at restored local files, and
 * the inventory lists what a backup needs from the bucket.
 */
class MediaRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Slide $slide;

    private string $mirror;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('r2', ['url' => 'https://media.test']);
        $this->mirror = storage_path('framework/testing/mirror-' . uniqid());
        config([
            'media.cache.path' => storage_path('framework/testing/media-cache-' . uniqid()),
            'media.offload_disk' => 'r2',
        ]);
        $this->slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => User::factory()->create()->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mirror);
        File::deleteDirectory(config('media.cache.path'));
        parent::tearDown();
    }

    private function offloaded(string $uuid, string $bytes = 'video-bytes'): SlideMedia
    {
        Storage::disk('r2')->put("slides/{$uuid}.mp4", $bytes);

        return $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => "{$uuid}.mp4", 'original_filename' => 'v.mp4',
            'disk_path' => "slides/{$uuid}.mp4", 'disk' => 'r2', 'file_size' => strlen($bytes), 'mime_type' => 'video/mp4',
        ]);
    }

    // ── Trash ────────────────────────────────────────────────────────────────

    public function test_deleting_an_offloaded_file_moves_it_to_the_trash(): void
    {
        $media = $this->offloaded('aaaaaaaa-0000-0000-0000-000000000001');

        $media->deleteFiles();

        Storage::disk('r2')->assertMissing($media->disk_path);
        Storage::disk('r2')->assertExists('trash/' . $media->disk_path);
    }

    public function test_with_no_trash_prefix_it_is_deleted_outright(): void
    {
        config(['media.trash_prefix' => '']);
        $media = $this->offloaded('aaaaaaaa-0000-0000-0000-000000000002');

        $media->deleteFiles();

        Storage::disk('r2')->assertMissing([$media->disk_path, 'trash/' . $media->disk_path]);
    }

    // ── media:verify ─────────────────────────────────────────────────────────

    public function test_verify_passes_when_everything_is_there(): void
    {
        $this->offloaded('bbbbbbbb-0000-0000-0000-000000000001');

        $this->artisan('media:verify')->expectsOutput('No files missing.')->assertSuccessful();
    }

    public function test_verify_finds_a_file_in_the_trash_and_restore_trash_brings_it_back(): void
    {
        // As after restoring a backup taken before the file was deleted.
        $media = $this->offloaded('bbbbbbbb-0000-0000-0000-000000000002');
        Storage::disk('r2')->move($media->disk_path, 'trash/' . $media->disk_path);

        $this->artisan('media:verify')
            ->expectsOutputToContain('1 file(s) missing')
            ->expectsOutputToContain('--restore-trash')
            ->assertFailed();

        $this->artisan('media:verify --restore-trash')
            ->expectsOutputToContain('Moved 1 file(s) back out of the bucket trash.')
            ->assertSuccessful();
        Storage::disk('r2')->assertExists($media->disk_path);
    }

    public function test_verify_reports_missing_local_files_and_suggests_rebuilding_thumbnails(): void
    {
        $media = $this->offloaded('bbbbbbbb-0000-0000-0000-000000000003');
        $media->update(['thumbnail_path' => 'thumbs/gone.jpg']);

        $this->artisan('media:verify')->expectsOutputToContain('slide:resync-thumbnails')->assertFailed();
        $this->artisan('media:verify --skip-derived')->assertSuccessful();
    }

    public function test_verify_checks_a_bucket_mirror(): void
    {
        $media = $this->offloaded('bbbbbbbb-0000-0000-0000-000000000004', 'twelve bytes');

        $this->artisan('media:verify', ['--mirror' => $this->mirror . '-nope'])->assertFailed();

        File::ensureDirectoryExists($this->mirror . '/slides');
        $this->artisan('media:verify', ['--mirror' => $this->mirror])
            ->expectsOutputToContain('not in the mirror')
            ->assertFailed();

        file_put_contents("{$this->mirror}/{$media->disk_path}", 'truncated');   // wrong size
        $this->artisan('media:verify', ['--mirror' => $this->mirror])->assertFailed();

        file_put_contents("{$this->mirror}/{$media->disk_path}", 'twelve bytes');
        $this->artisan('media:verify', ['--mirror' => $this->mirror])
            ->expectsOutput('The mirror holds every offloaded file.')
            ->assertSuccessful();
    }

    // ── media:localize --relink ──────────────────────────────────────────────

    public function test_relink_points_rows_at_files_already_on_the_local_disk(): void
    {
        config(['media.offload_disk' => null]);
        $present = $this->offloaded('cccccccc-0000-0000-0000-000000000001', 'present');
        $wrong   = $this->offloaded('cccccccc-0000-0000-0000-000000000002', 'right size');
        $absent  = $this->offloaded('cccccccc-0000-0000-0000-000000000003');

        // A restored mirror: one file intact, one truncated, one missing.
        Storage::disk('public')->put($present->disk_path, 'present');
        Storage::disk('public')->put($wrong->disk_path, 'short');
        // The bucket is gone.
        Storage::disk('r2')->deleteDirectory('slides');

        $this->artisan('media:localize --relink')->assertSuccessful();

        $this->assertSame('public', $present->fresh()->disk);
        $this->assertSame('r2', $wrong->fresh()->disk);
        $this->assertSame('r2', $absent->fresh()->disk);
    }

    public function test_relink_never_touches_the_bucket(): void
    {
        $media = $this->offloaded('cccccccc-0000-0000-0000-000000000004', 'bytes');
        Storage::disk('public')->put($media->disk_path, 'bytes');

        $this->artisan('media:localize --relink')->assertSuccessful();

        $this->assertSame('public', $media->fresh()->disk);
        Storage::disk('r2')->assertExists($media->disk_path);
        Storage::disk('r2')->assertMissing('trash/' . $media->disk_path);
    }

    // ── Inventory / backup manifest ──────────────────────────────────────────

    public function test_the_inventory_lists_every_offloaded_object_once(): void
    {
        $media = $this->offloaded('dddddddd-0000-0000-0000-000000000001');
        $media->update(['thumbnail_path' => 'thumbs/x.jpg']);
        $local = $this->slide->media()->create([
            'media_type' => 'color-flyer', 'filename' => 'f.pdf', 'original_filename' => 'f.pdf',
            'disk_path' => 'slides/f.pdf', 'file_size' => 10, 'mime_type' => 'application/pdf',
        ]);
        SlideAnnouncerRelease::create([
            'kind' => 'app', 'version' => '0.4.1_0.1.0', 'architecture' => 'arm64', 'release_type' => 'full',
            'disk_path' => 'slide-announcer/releases/app/arm64/0.4.1_0.1.0-abc.tar.gz', 'disk' => 'r2', 'sha256' => 'abc',
        ]);

        $objects = app(MediaInventory::class)->offloadedObjects();

        $this->assertEqualsCanonicalizing([
            ['disk' => 'r2', 'key' => $media->disk_path, 'size' => strlen('video-bytes')],
            ['disk' => 'r2', 'key' => 'slide-announcer/releases/app/arm64/0.4.1_0.1.0-abc.tar.gz', 'size' => null],
        ], $objects);
        $this->assertNotContains($local->disk_path, array_column($objects, 'key'));
    }
}

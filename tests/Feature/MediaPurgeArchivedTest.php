<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Support\MediaInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * media:purge-archived removes long-archived slides' offloaded files from the
 * bucket (to its trash) while keeping their records, and un-archiving or
 * --restore brings them back.
 */
class MediaPurgeArchivedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('r2', ['url' => 'https://media.test']);
        config(['media.cache.path' => storage_path('framework/testing/media-cache-' . uniqid())]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('media.cache.path'));
        parent::tearDown();
    }

    private function slideWithMedia(string $uuid, ?\DateTimeInterface $expires, string $disk = 'r2'): SlideMedia
    {
        $slide = Slide::create([
            'title' => "Slide {$uuid}", 'status' => 'published', 'uploaded_by' => $this->admin->id, 'expires_at' => $expires,
        ]);
        Storage::disk($disk)->put("slides/{$uuid}.mp4", 'video-bytes');
        Storage::disk('public')->put("thumbs/{$uuid}.jpg", 'thumb');

        return $slide->media()->create([
            'media_type' => 'slide', 'filename' => "{$uuid}.mp4", 'original_filename' => 'v.mp4',
            'disk_path' => "slides/{$uuid}.mp4", 'disk' => $disk, 'file_size' => 11, 'mime_type' => 'video/mp4',
            'thumbnail_path' => "thumbs/{$uuid}.jpg",
        ]);
    }

    public function test_only_offloaded_media_of_slides_archived_long_enough_is_purged(): void
    {
        $old     = $this->slideWithMedia('aaaaaaaa-0000-0000-0000-000000000001', now()->subDays(200));
        $recent  = $this->slideWithMedia('aaaaaaaa-0000-0000-0000-000000000002', now()->subDays(30));
        $current = $this->slideWithMedia('aaaaaaaa-0000-0000-0000-000000000003', null);
        $local   = $this->slideWithMedia('aaaaaaaa-0000-0000-0000-000000000004', now()->subDays(200), 'public');

        $this->artisan('media:purge-archived')->expectsOutputToContain('Purged 1 media file(s)')->assertSuccessful();

        $this->assertNotNull($old->fresh()->purged_at);
        Storage::disk('r2')->assertMissing($old->disk_path);
        Storage::disk('r2')->assertExists('trash/' . $old->disk_path);
        Storage::disk('public')->assertExists($old->thumbnail_path);   // the archive keeps its preview

        foreach ([$recent, $current, $local] as $kept) {
            $this->assertNull($kept->fresh()->purged_at);
            Storage::disk($kept->disk)->assertExists($kept->disk_path);
        }
    }

    public function test_days_option_dry_run_and_deleted_slides(): void
    {
        $archived = $this->slideWithMedia('bbbbbbbb-0000-0000-0000-000000000001', now()->subDays(40));
        $deleted  = $this->slideWithMedia('bbbbbbbb-0000-0000-0000-000000000002', null);
        $deleted->slide->delete();
        Slide::withTrashed()->whereKey($deleted->slide_id)->update(['deleted_at' => now()->subDays(40)]);

        $this->artisan('media:purge-archived --days=30 --dry-run')->expectsOutputToContain('Would purge 2 media file(s)')->assertSuccessful();
        $this->assertNull($archived->fresh()->purged_at);

        $this->artisan('media:purge-archived --days=30')->assertSuccessful();
        $this->assertNotNull($archived->fresh()->purged_at);
        $this->assertNotNull($deleted->fresh()->purged_at);
    }

    public function test_unarchiving_brings_media_back_from_the_trash(): void
    {
        $media = $this->slideWithMedia('cccccccc-0000-0000-0000-000000000001', now()->subDays(200));
        $this->artisan('media:purge-archived')->assertSuccessful();

        $this->actingAs($this->admin)->post(route('admin.slides.unarchive', $media->slide))
            ->assertSessionHas('success');

        $this->assertNull($media->fresh()->purged_at);
        Storage::disk('r2')->assertExists($media->disk_path);
    }

    public function test_unarchiving_warns_when_the_media_is_only_in_the_offline_backup(): void
    {
        $media = $this->slideWithMedia('cccccccc-0000-0000-0000-000000000002', now()->subDays(200));
        $this->artisan('media:purge-archived')->assertSuccessful();
        Storage::disk('r2')->delete('trash/' . $media->disk_path);   // the lifecycle rule ran

        $this->actingAs($this->admin)->post(route('admin.slides.unarchive', $media->slide))
            ->assertSessionHas('error');
        $this->assertNotNull($media->fresh()->purged_at);

        // media:verify flags purged media on a slide that is back in use.
        $this->artisan('media:verify --skip-derived')->expectsOutputToContain('no longer archived')->assertFailed();

        // After uploading the backup mirror to the bucket, --restore finishes the job.
        $this->artisan('media:purge-archived --restore')->assertFailed();
        Storage::disk('r2')->put($media->disk_path, 'video-bytes');
        $this->artisan('media:purge-archived --restore')->expectsOutputToContain('Restored 1')->assertSuccessful();
        $this->assertNull($media->fresh()->purged_at);
    }

    public function test_purged_media_is_left_out_of_the_inventory_and_verify_but_downloads_say_gone(): void
    {
        $media = $this->slideWithMedia('dddddddd-0000-0000-0000-000000000001', now()->subDays(200));
        $this->artisan('media:purge-archived')->assertSuccessful();

        $this->assertSame([], app(MediaInventory::class)->offloadedObjects());
        $this->artisan('media:verify')->expectsOutput('No files missing.')->assertSuccessful();

        $this->actingAs($this->admin)->get(route('slides.download', $media->slide->token))->assertStatus(410);
    }
}

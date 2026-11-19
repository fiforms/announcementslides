<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\LibraryItem;
use App\Models\Show;
use App\Models\SlideAnnouncer;
use App\Models\User;
use App\Services\ShowFrame;
use App\Support\LibraryFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Library audio and a show's background music: uploading, the show's
 * single-track playlist, and what players and devices receive.
 */
class ShowAudioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $leader;
    private Entity $entity;
    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->entity = Entity::create(['name' => 'Entity A']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->entity->id, ['role' => 'admin']);
        $this->show = $this->entity->mainShow();
    }

    /** Real audio: ffmpeg writes a short sine tone (the test is skipped without it). */
    private function assembledAudio(string $ext = 'mp3', string $mime = 'audio/mpeg', int $hz = 440): array
    {
        $path = 'slides/' . Str::uuid() . ".{$ext}";
        Storage::disk('public')->makeDirectory('slides');
        exec(sprintf(
            '%s -v error -y -f lavfi -i %s -t 2 %s 2>&1',
            escapeshellarg(config('slides.ffmpeg_binary')),
            escapeshellarg("sine=frequency={$hz}"),
            escapeshellarg(Storage::disk('public')->path($path))
        ), $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg cannot make a test tone here.');
        }

        return ['disk_path' => $path, 'original_filename' => 'Calm-Piano_Loop.' . $ext, 'mime_type' => $mime];
    }

    private function addAudio(array $upload)
    {
        return $this->actingAs($this->admin)->post(route('admin.library.audio.store'), ['upload' => $upload]);
    }

    private function audioItem(): LibraryItem
    {
        $this->addAudio($this->assembledAudio())->assertSessionHasNoErrors();

        return LibraryItem::audio()->firstOrFail();
    }

    private function saveAudio(array $data, ?User $as = null, ?Show $show = null, ?int $entityId = null)
    {
        $show ??= $this->show;

        return $this->actingAs($as ?? $this->leader)->put(
            route('shows.audio.save', ['show' => $show->id, 'entity_id' => $entityId ?? $this->entity->id]), $data
        );
    }

    public function test_audio_is_stored_once_under_its_content_address_with_its_length(): void
    {
        $upload = $this->assembledAudio('m4a', 'audio/mp4');
        $this->addAudio($upload)->assertRedirect();

        $item = LibraryItem::audio()->firstOrFail();
        $this->assertSame(LibraryFiles::audioPath($item->sha256, 'm4a'), $item->disk_path);
        $this->assertSame('Calm Piano Loop', $item->title);
        $this->assertSame(2, $item->duration_seconds);
        Storage::disk('public')->assertExists($item->disk_path);
        Storage::disk('public')->assertMissing($upload['disk_path']);

        $again = ['disk_path' => 'slides/' . Str::uuid() . '.m4a'] + $upload;
        Storage::disk('public')->put($again['disk_path'], Storage::disk('public')->get($item->disk_path));
        $this->addAudio($again);
        $this->assertSame(1, LibraryItem::audio()->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('library/audio'));
    }

    public function test_a_file_without_audio_is_rejected(): void
    {
        $path = 'slides/' . Str::uuid() . '.mp3';
        Storage::disk('public')->put($path, 'not audio at all');

        $this->addAudio(['disk_path' => $path, 'original_filename' => 'x.mp3', 'mime_type' => 'audio/mpeg'])->assertStatus(422);
        $this->assertSame(0, LibraryItem::audio()->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_only_admins_add_audio_and_only_audio_types_are_accepted(): void
    {
        $upload = $this->assembledAudio();
        $this->actingAs($this->leader)->post(route('admin.library.audio.store'), ['upload' => $upload])->assertForbidden();

        $this->addAudio(['mime_type' => 'video/mp4'] + $upload)->assertSessionHasErrors('upload.mime_type');
    }

    public function test_audio_never_appears_among_backgrounds_and_swept_files_spare_it(): void
    {
        $item = $this->audioItem();
        $this->assertSame(0, LibraryItem::backgrounds()->count());

        $this->assertSame([], app(LibraryFiles::class)->sweep(0));
        Storage::disk('public')->assertExists($item->disk_path);

        $item->delete();
        $this->assertNotEmpty(app(LibraryFiles::class)->sweep(0));
        Storage::disk('public')->assertMissing($item->disk_path);
    }

    public function test_a_leader_sets_the_shows_music_and_volume_and_clears_it(): void
    {
        $item = $this->audioItem();

        $this->saveAudio(['library_item_id' => $item->id, 'volume' => 40])->assertSessionHasNoErrors();
        $show = $this->show->fresh();
        $this->assertSame([$item->id], $show->audioItems->pluck('id')->all());
        $this->assertSame(40, $show->frame_audio_volume);

        // Picking another track replaces it: a single entry today.
        $this->addAudio($this->assembledAudio('mp3', 'audio/mpeg', 880));
        $other = LibraryItem::audio()->where('id', '!=', $item->id)->firstOrFail();
        $this->saveAudio(['library_item_id' => $other->id]);
        $this->assertSame([$other->id], $this->show->fresh()->audioItems->pluck('id')->all());

        // Volume alone leaves the track alone.
        $this->saveAudio(['volume' => 10]);
        $this->assertSame([$other->id], $this->show->fresh()->audioItems->pluck('id')->all());
        $this->assertSame(10, $this->show->fresh()->frame_audio_volume);

        $this->saveAudio(['library_item_id' => null]);
        $this->assertCount(0, $this->show->fresh()->audioItems);
    }

    public function test_music_is_independent_of_the_background_and_validated(): void
    {
        $item = $this->audioItem();
        $this->show->update(['frame_youtube' => ['video' => 'dQw4w9WgXcQ']]);

        $this->saveAudio(['library_item_id' => $item->id]);
        $this->assertNotNull($this->show->fresh()->frame_youtube);

        $this->saveAudio(['library_item_id' => 99999])->assertSessionHasErrors('library_item_id');
        $this->saveAudio(['volume' => 101])->assertSessionHasErrors('volume');

        $template = LibraryItem::create(['kind' => 'template', 'title' => 'T', 'disk_path' => 'library/templates/t.svg', 'mime_type' => 'image/svg+xml']);
        $this->saveAudio(['library_item_id' => $template->id])->assertStatus(422);
    }

    public function test_another_entitys_leader_cannot_change_the_music(): void
    {
        $item = $this->audioItem();
        $other = Entity::create(['name' => 'Entity B']);
        $outsider = User::factory()->create();
        $outsider->entities()->attach($other->id, ['role' => 'admin']);

        $this->saveAudio(['library_item_id' => $item->id], $outsider, null, $other->id)->assertNotFound();
        $this->assertCount(0, $this->show->fresh()->audioItems);
    }

    public function test_deleting_the_library_item_removes_it_from_shows(): void
    {
        $item = $this->audioItem();
        $this->saveAudio(['library_item_id' => $item->id]);

        $this->actingAs($this->admin)->delete(route('admin.library.destroy', $item))->assertRedirect();

        $this->assertCount(0, $this->show->fresh()->audioItems);
        $this->assertNull(app(ShowFrame::class)->forPlayer($this->show->fresh()));
    }

    public function test_players_and_devices_receive_the_playlist_even_with_no_other_frame(): void
    {
        $item = $this->audioItem();
        $this->saveAudio(['library_item_id' => $item->id, 'volume' => 55]);

        $frame = app(ShowFrame::class)->forPlayer($this->show->fresh());
        $this->assertSame(55, $frame['audio']['volume']);
        $this->assertSame($item->id, $frame['audio']['tracks'][0]['id']);
        $this->assertSame($item->file_url, $frame['audio']['tracks'][0]['url']);
        $this->assertNull($frame['file_url']);

        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();
        $sync = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/shows')->assertOk();
        $show = collect($sync->json('shows'))->firstWhere('id', (string) $this->show->id);
        $this->assertSame($item->file_url, $show['frame']['audio']['tracks'][0]['url']);
        $this->assertSame(55, $show['frame']['audio']['volume']);
    }

    public function test_the_editor_page_lists_library_audio_and_the_current_track(): void
    {
        $item = $this->audioItem();
        $this->saveAudio(['library_item_id' => $item->id]);

        $this->actingAs($this->leader)->followingRedirects()->get(route('shows.index', ['entity_id' => $this->entity->id]))
            ->assertInertia(fn ($page) => $page
                ->where('frame.audio.tracks.0.id', $item->id)
                ->where('libraryAudio.0.id', $item->id));
    }
}

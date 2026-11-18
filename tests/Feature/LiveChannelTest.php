<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\LiveChannel;
use App\Models\PlayLink;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideAnnouncer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live channels: an admin-curated catalog of live streams a show can use as
 * its background. Covers who may manage the catalog, picking one for a show
 * (exclusive with the other backgrounds), and what players receive.
 */
class LiveChannelTest extends TestCase
{
    use RefreshDatabase;

    private const JETSTREAM = 'https://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L';
    private const HLS = 'https://3abn.tulixcdn.com/3abn2/3abn_live/smil:3abn_live.smil/playlist.m3u8';

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

    private function channel(string $url = self::JETSTREAM, string $name = 'Hope Channel'): LiveChannel
    {
        $this->actingAs($this->admin)->post(route('admin.live-channels.store'), ['name' => $name, 'url' => $url])
            ->assertSessionHasNoErrors();

        return LiveChannel::where('name', $name)->firstOrFail();
    }

    private function pick(?int $channelId, bool $muted = false, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->leader)->put(
            route('shows.channel.save', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['channel_id' => $channelId, 'muted' => $muted]
        );
    }

    private function slide(string $mime, string $title): Slide
    {
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => $this->leader->id, 'entity_id' => $this->entity->id]);
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => $title, 'original_filename' => $title,
            'disk_path' => "slides/{$title}", 'file_size' => 100, 'mime_type' => $mime,
        ]);
        $this->show->slides()->attach($slide->id, ['sort_order' => $slide->id]);

        return $slide;
    }

    // ── Catalog ──────────────────────────────────────────────────────────────

    public function test_an_admin_adds_a_channel_storing_only_parsed_ids(): void
    {
        $channel = $this->channel('<iframe src="' . self::JETSTREAM . '"></iframe>');

        $this->assertSame('jetstream', $channel->provider);
        $this->assertSame(['id' => 'jsl:0sUSK6VA7GTNxQaY8E8L'], $channel->source);
        $this->assertSame($this->admin->id, $channel->created_by);
    }

    public function test_unsupported_links_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.live-channels.store'), ['name' => 'X', 'url' => 'https://hopetv.org/watch/hope-channel'])
            ->assertSessionHasErrors('url');
        $this->actingAs($this->admin)->post(route('admin.live-channels.store'), ['name' => 'X', 'url' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('url');
        $this->assertSame(0, LiveChannel::count());
    }

    public function test_only_global_admins_manage_the_catalog(): void
    {
        $this->actingAs($this->leader)->get(route('admin.live-channels.index'))->assertForbidden();
        $this->actingAs($this->leader)->post(route('admin.live-channels.store'), ['name' => 'X', 'url' => self::JETSTREAM])
            ->assertForbidden();
        $this->assertSame(0, LiveChannel::count());

        $this->actingAs($this->admin)->get(route('admin.live-channels.index'))->assertOk();
    }

    public function test_editing_a_channel_updates_every_show_using_it(): void
    {
        $channel = $this->channel();
        $this->pick($channel->id);

        $this->actingAs($this->admin)->patch(route('admin.live-channels.update', $channel), ['name' => 'Hope', 'url' => self::HLS])
            ->assertSessionHasNoErrors();

        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $this->app['auth']->forgetGuards();
        $this->getJson(route('play.slides', ['token' => $link->token]))
            ->assertOk()
            ->assertJsonPath('frame.channel', ['kind' => 'hls', 'url' => self::HLS, 'muted' => false]);
    }

    public function test_deleting_a_channel_clears_it_from_shows(): void
    {
        $channel = $this->channel();
        $this->pick($channel->id);

        $this->actingAs($this->admin)->delete(route('admin.live-channels.destroy', $channel));

        $this->assertNull($this->show->fresh()->frame_channel_id);
    }

    // ── Picking one for a show ───────────────────────────────────────────────

    public function test_a_channel_replaces_other_backgrounds_and_vice_versa(): void
    {
        $channel = $this->channel();
        $this->actingAs($this->leader)->put(
            route('shows.youtube.save', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['url' => 'dQw4w9WgXcQ']
        );

        $this->pick($channel->id)->assertSessionHasNoErrors();
        $show = $this->show->fresh();
        $this->assertSame($channel->id, $show->frame_channel_id);
        $this->assertNull($show->frame_youtube);

        // An uploaded background replaces the channel.
        $name = Str::uuid() . '.jpg';
        Storage::disk('public')->put("slides/{$name}", 'x');
        $this->actingAs($this->leader)->post(
            route('shows.media.store', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['filename' => $name, 'disk_path' => "slides/{$name}", 'original_filename' => 'bg', 'file_size' => 1, 'mime_type' => 'image/jpeg']
        );
        $this->assertNull($this->show->fresh()->frame_channel_id);

        // ...and picking the channel again removes the upload.
        $this->pick($channel->id);
        $this->assertNull($this->show->fresh()->baseMedia);

        $this->pick(null)->assertSessionHasNoErrors();
        $this->assertNull($this->show->fresh()->frame_channel_id);
    }

    public function test_only_the_entitys_admins_can_pick_a_channel(): void
    {
        $channel = $this->channel();
        $viewer = User::factory()->create();
        $viewer->entities()->attach($this->entity->id, ['role' => 'viewer']);

        $this->pick($channel->id, as: $viewer)->assertForbidden();
        $this->pick(999999)->assertSessionHasErrors('channel_id');
        $this->assertNull($this->show->fresh()->frame_channel_id);
    }

    // ── What players get ─────────────────────────────────────────────────────

    public function test_players_get_the_channel_and_video_slides_are_skipped(): void
    {
        $img = $this->slide('image/jpeg', 'img');
        $this->slide('video/mp4', 'vid');
        $zype = $this->channel('https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html?api_key=tq1hOEn0RGXLdpr2Z69t61rs2EPMmOk6Y4vh8YGDCBqNmUuSMfer2kzdp0nBup2z', 'AFTV');
        $this->pick($zype->id, muted: true);

        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $this->app['auth']->forgetGuards();
        $response = $this->getJson(route('play.slides', ['token' => $link->token]))->assertOk();
        $this->assertSame([$img->id], array_column($response->json('slides'), 'id'));
        $channel = $response->json('frame.channel');
        $this->assertSame('iframe', $channel['kind']);
        $this->assertTrue($channel['muted']);
        $this->assertStringStartsWith('https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html?', $channel['url']);
        $this->assertStringContainsString('mute=true', $channel['url']);

        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();
        $sync = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/shows')->assertOk();
        $show = collect($sync->json('shows'))->firstWhere('id', (string) $this->show->id);
        $this->assertSame($channel, $show['frame']['channel']);
        $this->assertSame([$img->id], array_column($show['slides'], 'id'));
    }

    public function test_mute_is_ignored_for_a_player_that_cannot_mute(): void
    {
        $this->pick($this->channel()->id, muted: true);

        $frame = app(\App\Services\ShowFrame::class)->forDevice($this->show->fresh());

        $this->assertSame(['kind' => 'iframe', 'url' => self::JETSTREAM, 'muted' => false], $frame['channel']);
    }

    public function test_a_channel_alone_makes_a_frame(): void
    {
        $this->pick($this->channel(self::HLS, '3ABN')->id);

        $frame = app(\App\Services\ShowFrame::class)->forDevice($this->show->fresh());

        $this->assertSame(['kind' => 'hls', 'url' => self::HLS, 'muted' => false], $frame['channel']);
        $this->assertNull($frame['file_url']);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\GeocodeEntities;
use App\Models\AdventistEntity;
use App\Models\Entity;
use App\Models\User;
use App\Services\DirectoryImport\EntityGeocoder;
use App\Services\DirectoryImport\EntitySyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EntityGeocodeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCensus(Entity $match, Entity $miss): void
    {
        Http::fake([
            EntityGeocoder::ENDPOINT => Http::response(
                "\"{$match->id}\",\"1 Main, Aiken, SC, 29801\",\"Match\",\"Exact\",\"1 MAIN ST\",\"-81.7,33.5\",\"1\",\"L\"\n"
                . "\"{$miss->id}\",\"2 Nowhere, X, SC, 29000\",\"No_Match\"\n"
            ),
        ]);
    }

    private function entities(): array
    {
        return [
            Entity::create(['name' => 'Aiken', 'address' => '1 Main', 'city' => 'Aiken', 'state' => 'SC', 'zip' => '29801']),
            Entity::create(['name' => 'Nowhere', 'address' => '2 Nowhere', 'city' => 'X', 'state' => 'SC', 'zip' => '29000']),
            Entity::create(['name' => 'Has coords', 'address' => '3 St', 'zip' => '29801', 'latitude' => 1.5, 'longitude' => 2.5]),
            Entity::create(['name' => 'No address', 'city' => 'Aiken']),
            Entity::create(['name' => 'Deactivated', 'address' => '4 St', 'zip' => '29801', 'deactivated' => true]),
        ];
    }

    public function test_only_missing_active_addressed_entities_are_looked_up_and_saved(): void
    {
        [$aiken, $nowhere, $has] = $this->entities();
        $this->fakeCensus($aiken, $nowhere);

        $result = app(EntityGeocoder::class)->geocode();

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(['Nowhere'], $result['unmatched_names']);
        $this->assertEquals(33.5, (float) $aiken->fresh()->latitude);
        $this->assertEquals(-81.7, (float) $aiken->fresh()->longitude);
        $this->assertNull($nowhere->fresh()->latitude);
        $this->assertEquals(1.5, (float) $has->fresh()->latitude, 'existing coordinates untouched');
        Http::assertSentCount(1);
    }

    public function test_dry_run_saves_nothing(): void
    {
        [$aiken, $nowhere] = $this->entities();
        $this->fakeCensus($aiken, $nowhere);

        $result = app(EntityGeocoder::class)->geocode(dryRun: true);

        $this->assertSame(1, $result['matched']);
        $this->assertNull($aiken->fresh()->latitude);
    }

    public function test_artisan_command_runs(): void
    {
        [$aiken, $nowhere] = $this->entities();
        $this->fakeCensus($aiken, $nowhere);

        $this->artisan('entity:geocode')->assertSuccessful();
        $this->assertNotNull($aiken->fresh()->latitude);
    }

    public function test_service_error_is_reported_as_failure(): void
    {
        $this->entities();
        Http::fake([EntityGeocoder::ENDPOINT => Http::response('boom', 500)]);

        $this->artisan('entity:geocode')->assertFailed();
    }

    public function test_sync_does_not_wipe_geocoded_coordinates(): void
    {
        $entity = Entity::create(['name' => 'Aiken', 'org_mast_id' => 'A1', 'latitude' => 33.5, 'longitude' => -81.7]);
        AdventistEntity::create(['conference_code' => 'C', 'org_mast_id' => 'A1', 'name' => 'Aiken']);

        app(EntitySyncer::class)->sync();

        $this->assertEquals(33.5, (float) $entity->fresh()->latitude);
    }

    public function test_admin_can_start_lookup_from_the_page_and_non_admins_cannot(): void
    {
        Queue::fake();
        Cache::flush();
        $this->entities();

        $this->actingAs(User::factory()->create(['role' => 'contributor']))
            ->post(route('admin.directory-import.geocode'))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.directory-import.geocode'))->assertSessionHas('success');
        Queue::assertPushed(GeocodeEntities::class);

        // A second click while running is refused.
        $this->actingAs($admin)->post(route('admin.directory-import.geocode'))->assertSessionHas('error');
        Queue::assertPushed(GeocodeEntities::class, 1);

        $this->withoutVite()->actingAs($admin)->get(route('admin.directory-import.index'))
            ->assertInertia(fn ($p) => $p->where('geocode.eligible', 2)->where('geocode.status.state', 'running'));
    }

    public function test_job_records_result_status(): void
    {
        Cache::flush();
        [$aiken, $nowhere] = $this->entities();
        $this->fakeCensus($aiken, $nowhere);

        GeocodeEntities::markQueued();
        (new GeocodeEntities)->handle(app(EntityGeocoder::class));

        $status = GeocodeEntities::status();
        $this->assertSame('done', $status['state']);
        $this->assertSame(1, $status['matched']);
        $this->assertSame(['Nowhere'], $status['unmatched_names']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdventistEntity;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private function dataFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.json', json_encode([
            'pastors' => [
                ['displayName' => 'Ann Pastor', 'email' => 'Ann@example.org', 'primaryPhone' => '1',
                    'churches' => ['Aiken SDA Church', 'Camden SDA Church']],
                ['displayName' => 'No Email', 'email' => '', 'churches' => []],
            ],
            'churchAddresses' => [
                'Aiken SDA Church' => ['orgCode' => 'ANT8B6', 'street' => '1 Main', 'city' => 'Aiken', 'state' => 'SC', 'zip' => '29801', 'membership' => 126],
                'Camden SDA Church' => ['orgCode' => 'ANT8CC', 'street' => '2 Main', 'city' => 'Camden', 'state' => 'SC', 'zip' => '29020'],
            ],
        ]));
    }

    public function test_non_admins_cannot_use_the_import(): void
    {
        $user = User::factory()->create(['role' => 'contributor']);

        $this->actingAs($user)->get(route('admin.directory-import.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.directory-import.entities'), ['file' => $this->dataFile()])->assertForbidden();
    }

    public function test_entity_json_import_previews_then_applies_without_blanking_coordinates(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        AdventistEntity::create([
            'conference_code' => 'CRLC', 'om_entity_id' => '9', 'org_mast_id' => 'ANT8B6',
            'name' => 'Aiken SDA Church', 'latitude' => 33.5, 'longitude' => -81.7, 'entity_type' => 'Church',
        ]);

        $this->actingAs($admin)->post(route('admin.directory-import.entities'), [
            'file' => $this->dataFile(), 'conference' => 'crlc', 'sync' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, AdventistEntity::count(), 'preview must not write');

        $this->actingAs($admin)->post(route('admin.directory-import.apply'))->assertSessionHas('success');

        $this->assertSame(2, AdventistEntity::count());
        $aiken = AdventistEntity::where('org_mast_id', 'ANT8B6')->first();
        $this->assertSame('1 Main', $aiken->address);
        $this->assertEquals(33.5, (float) $aiken->latitude);
        $this->assertSame('Church', $aiken->entity_type);
        $this->assertSame(126, $aiken->extra['membership']);
        $this->assertSame(2, Entity::count(), 'sync option should create live entities');
    }

    public function test_json_without_conference_is_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.directory-import.entities'), ['file' => $this->dataFile()])
            ->assertSessionHas('error');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pastor_import_creates_users_and_leaders_and_tracks_flags(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $aiken = Entity::create(['name' => 'Aiken SDA Church', 'org_mast_id' => 'ANT8B6']);
        $camden = Entity::create(['name' => 'Camden SDA Church', 'org_mast_id' => 'ANT8CC']);
        $gone = User::factory()->create(['email' => 'gone@example.org', 'role' => 'contributor']);
        $gone->created_by_import = true;
        $gone->save();
        $existing = User::factory()->create(['email' => 'ann@example.org', 'role' => 'viewer']);
        $existing->entities()->attach($camden->id, ['role' => 'viewer']);

        $this->actingAs($admin)->post(route('admin.directory-import.pastors'), ['file' => $this->dataFile()])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.directory-import.apply'))->assertSessionHas('success');

        $ann = User::where('email', 'ann@example.org')->first();
        $this->assertSame($existing->id, $ann->id, 'matched by email, case-insensitively');
        $this->assertSame('viewer', $ann->role, 'existing global role untouched');
        $this->assertFalse($ann->created_by_import);
        $this->assertTrue($ann->in_latest_import);
        $this->assertSame('admin', $ann->entities()->find($aiken->id)->pivot->role);
        $this->assertSame('admin', $ann->entities()->find($camden->id)->pivot->role, 'viewer upgraded');

        $gone->refresh();
        $this->assertFalse($gone->in_latest_import);

        $this->withoutVite()->actingAs($admin)->get(route('admin.directory-import.index'))
            ->assertOk()->assertInertia(fn ($page) => $page->has('missing', 1)->where('missing.0.email', 'gone@example.org'));
    }

    public function test_pastor_import_creates_new_user_as_contributor_flagged(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        Entity::create(['name' => 'Aiken SDA Church', 'org_mast_id' => 'ANT8B6']);

        $this->actingAs($admin)->post(route('admin.directory-import.pastors'), ['file' => $this->dataFile()]);
        $this->actingAs($admin)->post(route('admin.directory-import.apply'));

        $ann = User::where('email', 'ann@example.org')->firstOrFail();
        $this->assertSame('contributor', $ann->role);
        $this->assertNull($ann->password);
        $this->assertTrue($ann->created_by_import);
        $this->assertTrue($ann->in_latest_import);
        $this->assertSame(1, $ann->entities()->count(), 'unmatched church (Camden) is skipped');
    }

    public function test_sample_csvs_download_and_round_trip_through_the_importers(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);

        $entities = $this->actingAs($admin)->get(route('admin.directory-import.sample', 'entities'))->assertOk();
        $this->assertCount(4, explode("\n", trim($entities->streamedContent())));
        $this->actingAs($admin)->post(route('admin.directory-import.entities'), [
            'file' => UploadedFile::fake()->createWithContent('e.csv', $entities->streamedContent()),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->actingAs($admin)->post(route('admin.directory-import.apply'));
        $this->assertSame(3, AdventistEntity::count());

        $pastors = $this->actingAs($admin)->get(route('admin.directory-import.sample', 'pastors'))->assertOk();
        $this->assertCount(4, explode("\n", trim($pastors->streamedContent())));
        $this->actingAs($admin)->post(route('admin.directory-import.pastors'), [
            'file' => UploadedFile::fake()->createWithContent('p.csv', $pastors->streamedContent()),
        ])->assertSessionMissing('error');

        $this->actingAs($admin)->get(route('admin.directory-import.sample', 'bogus'))->assertNotFound();
    }
}

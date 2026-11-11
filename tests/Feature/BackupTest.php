<?php

namespace Tests\Feature;

use App\Casts\TolerantEncryptedArray;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\User;
use App\Models\Widget;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FakeDumper implements DatabaseDumper
{
    public ?string $loaded = null;

    public ?array $unsupported = null;

    public function driver(): string
    {
        return 'mysql';
    }

    public function unsupported(): ?array
    {
        return $this->unsupported;
    }

    public function dump(string $toFile): void
    {
        file_put_contents($toFile, "-- fake dump\nSELECT 1;\n");
    }

    public function load(string $fromFile): void
    {
        $this->loaded = file_get_contents($fromFile);
    }
}

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private FakeDumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        // Tests use fake disks, so back the "local roots" with real temp dirs too.
        config(['backup.disk' => 'local']);
        $this->dumper = new FakeDumper();
        $this->app->instance(DatabaseDumper::class, $this->dumper);
    }

    private function zipOf(Backup $b): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path('backups/'.$b->filename)) === true);

        return $zip;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_database_backup_contains_dump_and_manifest_without_key(): void
    {
        $svc = app(BackupService::class);
        $b = $svc->run($svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_CLI));

        $this->assertSame('completed', $b->status);
        $zip = $this->zipOf($b);
        $this->assertSame("-- fake dump\nSELECT 1;\n", $zip->getFromName('database.sql'));

        $m = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame(1, $m['format']);
        $this->assertSame('database', $m['mode']);
        $this->assertSame('mysql', $m['database']['driver']);
        $this->assertFalse($m['app_key_included']);
        $this->assertNull($m['files']);
        $this->assertStringNotContainsString(config('app.key'), $zip->getFromName('manifest.json'));
        $this->assertFalse($zip->locateName('files/public/x.txt'));
    }

    public function test_full_backup_includes_files_but_not_the_backup_dir(): void
    {
        file_put_contents(config('filesystems.disks.public.root').'/__bk_test.txt', 'slide');
        @mkdir(config('filesystems.disks.local.root').'/widgets', 0775, true);
        file_put_contents(config('filesystems.disks.local.root').'/widgets/__bk_w.js', 'js');

        try {
            $svc = app(BackupService::class);
            $b = $svc->run($svc->start(Backup::MODE_FULL, Backup::TRIGGER_MANUAL));
            $zip = $this->zipOf($b);

            $this->assertSame('slide', $zip->getFromName('files/public/__bk_test.txt'));
            $this->assertSame('js', $zip->getFromName('files/private/widgets/__bk_w.js'));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->assertStringNotContainsString('files/private/backups', $zip->getNameIndex($i));
            }
        } finally {
            @unlink(config('filesystems.disks.public.root').'/__bk_test.txt');
            @unlink(config('filesystems.disks.local.root').'/widgets/__bk_w.js');
        }
    }

    public function test_prune_keeps_newest_scheduled_and_never_touches_manual(): void
    {
        config(['backup.keep' => 2]);
        $svc = app(BackupService::class);

        $manual = $svc->run($svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_MANUAL));
        $sched = [];
        foreach (range(1, 4) as $i) {
            $sched[] = $svc->run($svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_SCHEDULED));
        }

        $this->assertSame(2, $svc->prune());
        $this->assertNotNull(Backup::find($manual->id));
        $this->assertNull(Backup::find($sched[0]->id));
        $this->assertNull(Backup::find($sched[1]->id));
        $this->assertNotNull(Backup::find($sched[3]->id));
        Storage::disk('local')->assertMissing('backups/'.$sched[0]->filename);
    }

    public function test_failed_dump_marks_backup_failed(): void
    {
        $this->app->instance(DatabaseDumper::class, new class extends FakeDumper {
            public function dump(string $toFile): void
            {
                throw new \App\Services\Backup\BackupException('boom');
            }
        });

        $svc = app(BackupService::class);
        $b = $svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_CLI);

        try {
            $svc->run($b);
            $this->fail('expected exception');
        } catch (\App\Services\Backup\BackupException) {
        }

        $this->assertSame('failed', $b->fresh()->status);
        $this->assertSame('boom', $b->fresh()->error);
    }

    public function test_pre_migrate_skips_fresh_installs_and_current_schema(): void
    {
        // Schema is fully migrated in tests: nothing pending.
        $this->artisan('backup:create', ['--if-migrations-pending' => true, '--tag' => 'pre-migrate'])
            ->expectsOutputToContain('No pending migrations')
            ->assertSuccessful();
        $this->assertSame(0, Backup::count());

        \DB::table('migrations')->where('migration', 'like', '2026_11_11%')->delete();
        $this->artisan('backup:create', ['--if-migrations-pending' => true, '--tag' => 'pre-migrate'])
            ->assertSuccessful();
        $this->assertSame(1, Backup::where('trigger', 'pre-migrate')->count());
    }

    public function test_sync_from_disk_registers_orphans_and_forgets_missing(): void
    {
        $svc = app(BackupService::class);
        $b = $svc->run($svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_MANUAL));

        Backup::query()->delete();           // as if a restore swapped in an older table
        $svc->syncFromDisk();
        $this->assertSame(1, Backup::count());
        $this->assertSame($b->filename, Backup::first()->filename);
        $this->assertSame('manual', Backup::first()->trigger);

        Storage::disk('local')->delete('backups/'.$b->filename);
        $svc->syncFromDisk();
        $this->assertSame(0, Backup::count());
    }

    public function test_restore_loads_the_dump_and_refuses_newer_or_foreign_backups(): void
    {
        $svc = app(BackupService::class);
        $b = $svc->run($svc->start(Backup::MODE_DATABASE, Backup::TRIGGER_MANUAL));

        $this->artisan('backup:restore', ['file' => $b->filename, '--force' => true])->assertSuccessful();
        $this->assertSame("-- fake dump\nSELECT 1;\n", $this->dumper->loaded);
        $this->assertSame(1, Backup::where('trigger', 'pre-restore')->count());

        // A backup claiming a newer migration is refused.
        $path = Storage::disk('local')->path('backups/'.$b->filename);
        $zip = new ZipArchive();
        $zip->open($path);
        $m = json_decode($zip->getFromName('manifest.json'), true);
        $m['latest_migration'] = '2099_01_01_000000_future';
        $zip->addFromString('manifest.json', json_encode($m));
        $zip->close();

        $this->dumper->loaded = null;
        $this->artisan('backup:restore', ['file' => $b->filename, '--force' => true])
            ->expectsOutputToContain('newer version')
            ->assertFailed();
        $this->assertNull($this->dumper->loaded);
    }

    public function test_widget_settings_unreadable_with_another_key_read_as_null(): void
    {
        $w = Widget::create(['slug' => 'w', 'name' => 'W', 'version' => '1', 'manifest' => [], 'files' => [], 'settings' => ['api' => 'k']]);
        $this->assertSame(['api' => 'k'], $w->fresh()->settings);

        \DB::table('widgets')->where('id', $w->id)->update(['settings' => Crypt::encryptString('x') . 'garbage']);
        $this->assertNull($w->fresh()->settings);
        $this->assertInstanceOf(TolerantEncryptedArray::class, new TolerantEncryptedArray());
    }

    public function test_admin_can_start_download_and_delete_but_others_cannot(): void
    {
        Queue::fake();
        $this->withoutVite();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->post(route('admin.backups.store'), ['mode' => 'full'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.backups.store'), ['mode' => 'bogus'])->assertSessionHasErrors('mode');

        $this->actingAs($admin)->post(route('admin.backups.store'), ['mode' => 'full'])->assertRedirect();
        Queue::assertPushed(RunBackup::class);
        $row = Backup::first();
        $this->assertSame(['full', 'manual', 'queued'], [$row->mode, $row->trigger, $row->status]);

        // a second request while one is queued is refused
        $this->actingAs($admin)->post(route('admin.backups.store'), ['mode' => 'database']);
        $this->assertSame(1, Backup::count());

        (new RunBackup($row->id))->handle(app(BackupService::class));
        $row->refresh();
        $this->assertSame('completed', $row->status);

        $this->actingAs($admin)->get(route('admin.backups.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.backups.download', $row))->assertOk()
            ->assertHeader('content-type', 'application/zip');
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->get(route('admin.backups.download', $row))->assertForbidden();

        $this->actingAs($admin)->delete(route('admin.backups.destroy', $row))->assertRedirect();
        $this->assertSame(0, Backup::count());
        Storage::disk('local')->assertMissing('backups/'.$row->filename);
    }

    public function test_unsupported_install_gets_clear_errors_and_an_explanation_page(): void
    {
        $this->withoutVite();
        $this->dumper->unsupported = ['code' => 'driver', 'message' => 'Backups need a MySQL database, but this install uses sqlite. '];

        $this->artisan('backup:create')->expectsOutputToContain('Backups need a MySQL database')->assertFailed();
        $this->artisan('backup:restore', ['file' => 'x.zip', '--force' => true])->expectsOutputToContain('Backups need a MySQL database')->assertFailed();
        $this->assertSame(0, Backup::count());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.backups.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Backups')->where('unsupported', 'driver')->where('settings', null));
        $this->actingAs($admin)->post(route('admin.backups.store'), ['mode' => 'database'])
            ->assertSessionHas('error');
        $this->assertSame(0, Backup::count());
    }

    public function test_the_real_dumper_reports_sqlite_as_unsupported(): void
    {
        $reason = (new \App\Services\Backup\MysqlDumper())->unsupported();

        $this->assertSame('driver', $reason['code']);
        $this->assertStringContainsString('sqlite', $reason['message']);
    }
}

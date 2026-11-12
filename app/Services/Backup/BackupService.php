<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * The one backup mechanism: artisan (backup:create), the scheduler, the
 * pre-migrate hook in the Docker entrypoint and the admin page all end up in
 * run(). An archive is a ZIP:
 *
 *   manifest.json        what, when, which app/migration/db, whether files are in
 *   database.sql         mysqldump output (deflated)
 *   files/public/...     full mode: uploaded slides and thumbnails (stored)
 *   files/private/...    full mode: widget bundles etc. (stored; not backups/)
 *
 * The APP_KEY is never included, only a fingerprint so a restore can warn.
 */
class BackupService
{
    public const FORMAT = 1;
    public const LOCK = 'backup.running';

    public function __construct(private DatabaseDumper $dumper)
    {
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('backup.disk'));
    }

    public function dir(): string
    {
        return trim(config('backup.path'), '/');
    }

    /** @return array{code: string, message: string}|null see DatabaseDumper::unsupported() */
    public function unsupported(): ?array
    {
        return $this->dumper->unsupported();
    }

    /** @throws BackupException when backups cannot work on this install */
    public function assertSupported(): void
    {
        if ($reason = $this->unsupported()) {
            throw new BackupException(trim($reason['message']));
        }
    }

    /** Create the bookkeeping row; run() does the work (now or from a queued job). */
    public function start(string $mode, string $trigger, ?int $userId = null): Backup
    {
        $this->assertSupported();

        return Backup::create([
            'mode'       => $mode,
            'trigger'    => $trigger,
            'status'     => 'queued',
            'created_by' => $userId,
        ]);
    }

    public function isRunning(): bool
    {
        return Backup::whereIn('status', ['queued', 'running'])
            ->where('updated_at', '>', now()->subMinutes(config('backup.stale_minutes')))
            ->exists();
    }

    /** Build the archive for $backup. Marks the row failed and rethrows on error. */
    public function run(Backup $backup): Backup
    {
        $lock = Cache::lock(self::LOCK, config('backup.stale_minutes') * 60);

        if (! $lock->get()) {
            $this->fail($backup, new BackupException('Another backup is already running.'));
            throw new BackupException('Another backup is already running.');
        }

        $tmp = null;
        $zipPath = null;

        try {
            $backup->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

            $name = sprintf('backup-%s-%s-%s.zip', now()->format('Ymd-His'), $backup->mode, $backup->id);
            $tmpDir = $this->workDir();
            $sql = $tmpDir.'/database-'.$backup->id.'.sql';
            $zipPath = $tmpDir.'/'.$name.'.partial';
            $tmp = $sql;

            $this->dumper->dump($sql);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BackupException('Could not create the archive.');
            }

            $files = ['public' => 0, 'private' => 0];
            $skipped = [];

            $zip->addFile($sql, 'database.sql');
            $zip->setCompressionName('database.sql', ZipArchive::CM_DEFLATE);

            if ($backup->mode === Backup::MODE_FULL) {
                foreach (['public' => 'public', 'private' => 'local'] as $label => $diskName) {
                    $root = $this->localRoot($diskName);
                    if ($root === null) {
                        $skipped[] = $label;
                        continue;
                    }
                    $files[$label] = $this->addTree($zip, $root, 'files/'.$label.'/', $label === 'private');
                }
            }

            $manifest = $this->manifest($backup, $files, $skipped);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // ZipArchive writes everything on close; files must still exist until then.
            if (! $zip->close()) {
                throw new BackupException('Could not write the archive (disk full?).');
            }

            $stream = fopen($zipPath, 'rb');
            $disk = $this->disk();
            $ok = $disk->writeStream($this->dir().'/'.$name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($ok === false) {
                throw new BackupException('Could not save the archive to the backup disk.');
            }

            $backup->update([
                'filename'    => $name,
                'status'      => 'completed',
                'size'        => filesize($zipPath),
                'manifest'    => $manifest,
                'finished_at' => now(),
            ]);

            return $backup;
        } catch (Throwable $e) {
            $this->fail($backup, $e);
            throw $e;
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
            }
            $lock->release();
        }
    }

    /** Delete the archive and its row. */
    public function delete(Backup $backup): void
    {
        if ($backup->filename) {
            $this->disk()->delete($this->dir().'/'.$backup->filename);
        }
        $backup->delete();
    }

    /** Keep the newest N scheduled and N safety archives; manual ones are never pruned. */
    public function prune(): int
    {
        $removed = 0;

        foreach ([[Backup::AUTO_TRIGGERS, config('backup.keep')], [Backup::SAFETY_TRIGGERS, config('backup.keep_safety')]] as [$triggers, $keep]) {
            $old = Backup::whereIn('trigger', $triggers)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->skip(max(0, (int) $keep))->take(PHP_INT_MAX)
                ->get();

            foreach ($old as $backup) {
                $this->delete($backup);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Make the table match the archives on disk: forget archives that are gone
     * and register ones that are not recorded (e.g. after a restore swapped in
     * an older backups table). Registration reads each new archive's manifest.
     */
    public function syncFromDisk(): void
    {
        $disk = $this->disk();
        $present = collect($disk->files($this->dir()))
            ->map(fn ($p) => basename($p))
            ->filter(fn ($n) => str_ends_with($n, '.zip'))
            ->values();

        Backup::where('status', 'completed')->whereNotIn('filename', $present)->delete();

        $known = Backup::pluck('filename')->filter()->all();

        foreach ($present->diff($known) as $name) {
            $manifest = $this->readManifestFromDisk($name);
            if ($manifest === null) {
                continue;
            }
            Backup::create([
                'filename'    => $name,
                'mode'        => $manifest['mode'] ?? Backup::MODE_DATABASE,
                'trigger'     => $manifest['trigger'] ?? Backup::TRIGGER_CLI,
                'status'      => 'completed',
                'size'        => $disk->size($this->dir().'/'.$name),
                'manifest'    => $manifest,
                'started_at'  => $manifest['created_at'] ?? null,
                'finished_at' => $manifest['created_at'] ?? null,
            ]);
        }
    }

    /** Migrations that exist as files but have not run, or null when this is a fresh install. */
    public function pendingMigrations(): ?int
    {
        if (! Schema::hasTable('migrations')) {
            return null;
        }

        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
        $ran = DB::table('migrations')->pluck('migration')->all();

        return count(array_diff($files, $ran));
    }

    public function latestMigration(): ?string
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->orderByDesc('id')->value('migration') : null;
    }

    public function latestMigrationFile(): ?string
    {
        $migrator = app('migrator');
        $names = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));

        return $names ? max($names) : null;
    }

    /** SHA-256 prefix of the app key: lets a restore notice a different key without storing it. */
    public function keyFingerprint(): ?string
    {
        $key = config('app.key');

        return $key ? substr(hash('sha256', (string) $key), 0, 12) : null;
    }

    public function dumper(): DatabaseDumper
    {
        return $this->dumper;
    }

    private function manifest(Backup $backup, array $files, array $skipped): array
    {
        return [
            'format'           => self::FORMAT,
            'app'              => config('app.name'),
            'app_version'      => env('APP_VERSION', 'dev'),
            'created_at'       => now()->toIso8601String(),
            'mode'             => $backup->mode,
            'trigger'          => $backup->trigger,
            'database'         => [
                'driver' => $this->dumper->driver(),
                'name'   => config('database.connections.'.config('database.default').'.database'),
                'file'   => 'database.sql',
            ],
            'latest_migration' => $this->latestMigration(),
            'files'            => $backup->mode === Backup::MODE_FULL ? ['public' => $files['public'], 'private' => $files['private']] : null,
            'files_skipped'    => $skipped,
            // Files on an offload disk (e.g. R2) are not in the archive; the
            // database still points at them, so they must be kept there.
            'offloaded_files'  => $backup->mode === Backup::MODE_FULL ? $this->offloadedSummary() : null,
            'app_key_included' => false,
            'app_key_fingerprint' => $this->keyFingerprint(),
        ];
    }

    /**
     * Media and release files living on a non-local disk, per disk:
     * {disk: {count, bytes}}. Empty when nothing is offloaded (or before the
     * storage-tier migration has run, e.g. for a pre-migrate backup).
     */
    private function offloadedSummary(): array
    {
        $summary = [];
        try {
            foreach (['slide_media' => 'file_size', 'slide_announcer_releases' => null] as $table => $sizeColumn) {
                if (! Schema::hasColumn($table, 'disk')) {
                    continue;
                }
                $rows = DB::table($table)->where('disk', '!=', 'public')
                    ->selectRaw('disk, count(*) as n'.($sizeColumn ? ", sum({$sizeColumn}) as bytes" : ''))
                    ->groupBy('disk')->get();
                foreach ($rows as $row) {
                    $summary[$row->disk]['count'] = ($summary[$row->disk]['count'] ?? 0) + (int) $row->n;
                    $summary[$row->disk]['bytes'] = ($summary[$row->disk]['bytes'] ?? 0) + (int) ($row->bytes ?? 0);
                }
            }
        } catch (Throwable) {
            // Informational only: never fail a backup over it.
        }

        return $summary;
    }

    /** Add a directory tree uncompressed; returns the file count. */
    private function addTree(ZipArchive $zip, string $root, string $prefix, bool $isPrivate): int
    {
        if (! is_dir($root)) {
            return 0;
        }

        $count = 0;
        $skipTop = $isPrivate ? [explode('/', $this->dir())[0]] : [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');

            if ($skipTop && in_array(explode('/', $rel)[0], $skipTop, true)) {
                continue;
            }
            if (basename($rel) === '.gitignore') {
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix.$rel);
            $zip->setCompressionName($prefix.$rel, ZipArchive::CM_STORE);
            $count++;
        }

        return $count;
    }

    /** Filesystem root of a local disk, or null when the disk is remote (S3). */
    private function localRoot(string $diskName): ?string
    {
        $cfg = config('filesystems.disks.'.$diskName);

        return ($cfg['driver'] ?? null) === 'local' ? $cfg['root'] : null;
    }

    /** Scratch directory for in-progress archives, inside storage so it has the room. */
    private function workDir(): string
    {
        $dir = storage_path('app/private/'.$this->dir().'/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function readManifestFromDisk(string $name): ?array
    {
        $disk = $this->disk();
        $path = $this->dir().'/'.$name;
        $copy = null;

        // Local disks are opened in place; a remote archive is copied down first.
        if (method_exists($disk, 'path')) {
            $file = $disk->path($path);
        } else {
            $file = $copy = tempnam($this->workDir(), 'm');
            $in = $disk->readStream($path);
            if (! $in) {
                @unlink($copy);

                return null;
            }
            file_put_contents($copy, $in);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($file) !== true) {
                return null;
            }
            $json = $zip->getFromName('manifest.json');
            $zip->close();

            return $json ? (json_decode($json, true) ?: null) : null;
        } finally {
            if ($copy) {
                @unlink($copy);
            }
        }
    }

    private function fail(Backup $backup, Throwable $e): void
    {
        $backup->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
    }
}

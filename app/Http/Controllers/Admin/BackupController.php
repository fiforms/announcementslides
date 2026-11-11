<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Backups: start a database-only or full backup (built by the queue
 * worker, same service as `artisan backup:create`), list, download, delete.
 * Restoring is deliberately CLI-only (`artisan backup:restore`).
 */
class BackupController extends Controller
{
    public function index(BackupService $backups): Response
    {
        // Not usable here (e.g. SQLite): the page shows an explanation instead.
        if ($reason = $backups->unsupported()) {
            return Inertia::render('Admin/Backups', ['unsupported' => $reason['code'], 'backups' => [], 'settings' => null, 'running' => false]);
        }

        $backups->syncFromDisk();

        return Inertia::render('Admin/Backups', [
            'backups' => Backup::with('creator:id,name')->orderByDesc('id')->limit(100)->get()->map(fn (Backup $b) => [
                'id'          => $b->id,
                'filename'    => $b->filename,
                'mode'        => $b->mode,
                'trigger'     => $b->trigger,
                'status'      => $b->status,
                'size'        => $b->size,
                'error'       => $b->error,
                'created_by'  => $b->creator?->name,
                'created_at'  => $b->created_at?->toIso8601String(),
                'finished_at' => $b->finished_at?->toIso8601String(),
                'app_version' => $b->manifest['app_version'] ?? null,
                'files'       => $b->manifest['files'] ?? null,
                'files_skipped' => $b->manifest['files_skipped'] ?? [],
            ]),
            'settings' => [
                'schedule'       => config('backup.schedule'),
                'time'           => config('backup.time'),
                'keep'           => config('backup.keep'),
                'keep_safety'    => config('backup.keep_safety'),
                'before_migrate' => config('backup.before_migrate'),
                'disk'           => config('backup.disk'),
            ],
            'running' => $backups->isRunning(),
        ]);
    }

    public function store(Request $request, BackupService $backups): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([Backup::MODE_DATABASE, Backup::MODE_FULL])],
        ]);

        if ($reason = $backups->unsupported()) {
            return back()->with('error', trim($reason['message']));
        }

        if ($backups->isRunning()) {
            return back()->with('error', 'A backup is already running.');
        }

        $backup = $backups->start($data['mode'], Backup::TRIGGER_MANUAL, $request->user()->id);
        RunBackup::dispatch($backup->id);

        return back()->with('success', 'Backup started. It will appear in the list when finished.');
    }

    public function download(Backup $backup, BackupService $backups): StreamedResponse
    {
        abort_unless($backup->status === 'completed' && $backup->filename, 404);

        $path = $backups->dir().'/'.$backup->filename;
        abort_unless($backups->disk()->exists($path), 404);

        set_time_limit(0);

        return $backups->disk()->download($path, $backup->filename, [
            'Content-Type'           => 'application/zip',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Backup $backup, BackupService $backups): RedirectResponse
    {
        if ($backup->isActive() && $backup->updated_at->gt(now()->subMinutes(config('backup.stale_minutes')))) {
            return back()->with('error', 'That backup is still running.');
        }

        $backups->delete($backup);

        return back()->with('success', 'Backup deleted.');
    }
}

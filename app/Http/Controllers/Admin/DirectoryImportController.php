<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GeocodeEntities;
use App\Services\DirectoryImport\EntityGeocoder;
use App\Services\DirectoryImport\EntityImporter;
use App\Services\DirectoryImport\EntitySyncer;
use App\Services\DirectoryImport\PastorImporter;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Upload -> preview -> confirm import of churches and pastors. The uploaded file
 * is parked on the private local disk between the preview and the confirm; the
 * confirm step only ever reads the path the server itself stored in the session.
 */
class DirectoryImportController extends Controller
{
    private const SESSION_KEY = 'directory_import';

    public function index(PastorImporter $pastors, EntityGeocoder $geocoder): Response
    {
        $pending = session(self::SESSION_KEY);

        return Inertia::render('Admin/DirectoryImport', [
            'pending' => $pending ? collect($pending)->except('path')->all() : null,
            'geocode' => [
                'eligible' => $geocoder->eligible()->count(),
                'status'   => GeocodeEntities::status(),
            ],
            'missing' => $pastors->missingFromLatestImport()->map(fn ($u) => [
                'id'             => $u->id,
                'name'           => $u->name,
                'email'          => $u->email,
                'role'           => $u->role,
                'entities_count' => $u->entities_count,
            ])->values(),
        ]);
    }

    public function previewEntities(Request $request, EntityImporter $importer): RedirectResponse
    {
        $request->validate([
            'file'       => ['required', 'file', 'max:5120', 'extensions:csv,txt,json'],
            'conference' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->stage($request, 'entities', function (string $path) use ($request, $importer) {
            $rows = $importer->parse($path, (string) $request->input('conference'));

            return [
                'summary' => $importer->preview($rows),
                'sync'    => $request->boolean('sync'),
            ];
        });
    }

    public function previewPastors(Request $request, PastorImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,json'],
        ]);

        return $this->stage($request, 'pastors', function (string $path) use ($importer) {
            $parsed = $importer->parse($path);

            return ['summary' => $importer->preview($parsed)];
        });
    }

    public function apply(
        Request $request,
        EntityImporter $entities,
        PastorImporter $pastors,
        EntitySyncer $syncer,
    ): RedirectResponse {
        $pending = session(self::SESSION_KEY);

        if (! $pending || ! Storage::disk('local')->exists($pending['path'])) {
            $this->discard();
            return back()->with('error', 'That import has expired. Please upload the file again.');
        }

        $path = Storage::disk('local')->path($pending['path']);

        try {
            if ($pending['kind'] === 'entities') {
                $rows = $entities->parse($path, (string) ($pending['conference'] ?? ''));
                $result = DB::transaction(fn () => $entities->apply($rows));
                $message = "Imported {$result['saved']} churches.";

                if (! empty($pending['sync'])) {
                    $conferences = collect($rows)->pluck('where.conference_code')->unique();
                    $s = ['created' => 0, 'updated' => 0, 'deactivated' => 0];
                    foreach ($conferences as $code) {
                        $r = $syncer->sync($code);
                        foreach ($s as $k => $_) {
                            $s[$k] += $r[$k];
                        }
                    }
                    $message .= " Synced to entities: {$s['created']} created, {$s['updated']} updated, {$s['deactivated']} deactivated.";
                }
            } else {
                $parsed = $pastors->parse($path);
                $result = DB::transaction(fn () => $pastors->apply($parsed, $request->user()));
                $message = "Pastors imported: {$result['created']} new users, {$result['linked']} church leader assignments.";
            }
        } catch (InvalidArgumentException $e) {
            $this->discard();
            return back()->with('error', $e->getMessage());
        }

        $this->discard();

        return back()->with('success', $message);
    }

    /** A header row plus a few obviously fake rows, in the format each import accepts. */
    public function sample(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['entities', 'pastors'], true), 404);

        if ($kind === 'entities') {
            $header = EntityImporter::CSV_COLUMNS;
            $rows = [
                ['EXAMPLE', '1001', 'EX0001', 'Church', 'Springfield SDA Church', '100 Main St', 'Springfield', 'NC', '27000', 'United States', '35.1234567', '-80.1234567', '555-0100', 'https://example.org/springfield', 'Jane Sample', 'en', '', '', '', '', '{"county":"Example"}'],
                ['EXAMPLE', '1002', 'EX0002', 'Company', 'Riverside Spanish SDA Company', '200 River Rd', 'Riverside', 'NC', '27001', 'United States', '', '', '555-0101', '', 'Juan Ejemplo', 'es', '', '', '', '', ''],
                ['EXAMPLE', '1003', 'EX0003', 'Church', 'Hilltop SDA Church', '300 Hill Ave', 'Hilltop', 'SC', '29000', 'United States', '', '', '', '', '', 'en', '', '', '', '', ''],
            ];
        } else {
            $header = ['name', 'email', 'phone', 'churches'];
            $rows = [
                ['Jane Sample', 'jane.sample@example.org', '555-0100', 'EX0001'],
                ['Juan Ejemplo', 'juan.ejemplo@example.org', '555-0101', 'EX0002;EX0003'],
                ['Pat Placeholder', 'pat.placeholder@example.org', '', 'Hilltop SDA Church'],
            ];
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "sample-{$kind}.csv", ['Content-Type' => 'text/csv']);
    }

    public function geocode(EntityGeocoder $geocoder): RedirectResponse
    {
        if (GeocodeEntities::isRunning()) {
            return back()->with('error', 'A coordinate lookup is already running.');
        }

        if ($geocoder->eligible()->count() === 0) {
            return back()->with('success', 'Every entity with an address already has coordinates.');
        }

        GeocodeEntities::markQueued();
        GeocodeEntities::dispatch();

        return back()->with('success', 'Coordinate lookup started. This page updates when it finishes.');
    }

    public function cancel(): RedirectResponse
    {
        $this->discard();

        return back();
    }

    /** Park the upload, run $build on it, and remember the preview in the session. */
    private function stage(Request $request, string $kind, callable $build): RedirectResponse
    {
        $this->discard();

        $upload = $request->file('file');
        // Server-chosen name and extension: never the client's filename.
        $ext = str_starts_with(ltrim((string) file_get_contents($upload->getRealPath()), "\xEF\xBB\xBF \t\r\n"), '{') ? 'json' : 'csv';
        $stored = $upload->storeAs('directory-imports', Str::uuid() . '.' . $ext, 'local');

        try {
            $extra = $build(Storage::disk('local')->path($stored));
        } catch (InvalidArgumentException $e) {
            Storage::disk('local')->delete($stored);
            return back()->with('error', $e->getMessage());
        }

        session([self::SESSION_KEY => [
            'kind'       => $kind,
            'path'       => $stored,
            'filename'   => $upload->getClientOriginalName(),
            'conference' => strtoupper(trim((string) $request->input('conference'))),
        ] + $extra]);

        return back();
    }

    private function discard(): void
    {
        $pending = session()->pull(self::SESSION_KEY);

        if ($pending && ! empty($pending['path'])) {
            Storage::disk('local')->delete($pending['path']);
        }
    }
}

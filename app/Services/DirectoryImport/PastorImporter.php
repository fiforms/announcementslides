<?php

namespace App\Services\DirectoryImport;

use App\Models\Entity;
use App\Models\User;
use InvalidArgumentException;

/**
 * Imports pastors as users and makes each an entity leader (user_entities role
 * `admin`) for their churches. Pastors are not a separate table.
 *
 * Input is the pastor-directory data.json, or a CSV with the columns
 * name,email,phone,churches (churches separated by ";", each an org code or a
 * church name).
 *
 * Existing users keep their global role; an existing entity role is never
 * downgraded; nothing is ever removed.
 */
class PastorImporter
{
    public const NEW_USER_ROLE = 'contributor';

    /**
     * @return array{pastors: list<array{name: string, email: string, phone: ?string, churches: list<string>}>, skipped: list<string>}
     *         `churches` hold org codes where known, otherwise the raw name.
     */
    public function parse(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        $pastors = str_starts_with(ltrim($raw, "\xEF\xBB\xBF \t\r\n"), '{')
            ? $this->parseJson($raw)
            : $this->parseCsv($path);

        $valid = [];
        $skipped = [];

        foreach ($pastors as $p) {
            $email = strtolower(trim($p['email']));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = ($p['name'] ?: '(no name)') . ' — no valid email';
                continue;
            }

            $valid[$email] = ['email' => $email] + $p; // de-dupe by email
        }

        if (! $valid) {
            throw new InvalidArgumentException('No pastors with a valid email were found.');
        }

        return ['pastors' => array_values($valid), 'skipped' => $skipped];
    }

    private function parseJson(string $raw): array
    {
        $json = json_decode($raw, true);

        if (! is_array($json) || ! isset($json['pastors']) || ! is_array($json['pastors'])) {
            throw new InvalidArgumentException('This JSON has no "pastors" section.');
        }

        $orgCodes = [];
        foreach (($json['churchAddresses'] ?? []) as $name => $c) {
            if (! empty($c['orgCode'])) {
                $orgCodes[$name] = $c['orgCode'];
            }
        }

        return array_map(function ($p) use ($orgCodes) {
            $name = trim((string) ($p['displayName'] ?? trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''))));

            return [
                'name'     => $name,
                'email'    => (string) ($p['email'] ?? ''),
                'phone'    => $p['primaryPhone'] ?? null,
                'churches' => array_map(fn ($c) => $orgCodes[$c] ?? $c, array_values((array) ($p['churches'] ?? []))),
            ];
        }, $json['pastors']);
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);
            throw new InvalidArgumentException('The CSV file has no header row.');
        }

        $header = array_map(fn ($h) => strtolower(trim(ltrim($h, "\xEF\xBB\xBF"))), $header);

        if (! in_array('email', $header, true) || ! in_array('name', $header, true)) {
            fclose($handle);
            throw new InvalidArgumentException('The CSV needs at least "name" and "email" columns (optional: phone, churches).');
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }
            $r = array_combine($header, $line);
            $rows[] = [
                'name'     => trim($r['name']),
                'email'    => $r['email'],
                'phone'    => ($r['phone'] ?? '') !== '' ? $r['phone'] : null,
                'churches' => array_values(array_filter(array_map('trim', explode(';', $r['churches'] ?? '')))),
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array{pastors: list<array>, skipped: list<string>}  $parsed
     * @return array{plan: list<array>, unmatched: list<string>, new_users: int, existing_users: int, new_links: int, upgrades: int, already: int, skipped: list<string>}
     */
    public function preview(array $parsed): array
    {
        $out = [
            'plan' => [], 'unmatched' => [], 'new_users' => 0, 'existing_users' => 0,
            'new_links' => 0, 'upgrades' => 0, 'already' => 0, 'skipped' => $parsed['skipped'],
        ];

        $unmatched = [];

        foreach ($parsed['pastors'] as $p) {
            $user = User::where('email', $p['email'])->first();
            $user ? $out['existing_users']++ : $out['new_users']++;

            $links = [];
            foreach ($p['churches'] as $ref) {
                $entity = $this->resolveEntity($ref);

                if (! $entity) {
                    $unmatched[$ref] = true;
                    continue;
                }

                $current = $user?->entities()->where('entities.id', $entity->id)->first()?->pivot->role;

                if ($current === 'admin') {
                    $out['already']++;
                    continue;
                }

                $current === 'viewer' ? $out['upgrades']++ : $out['new_links']++;
                $links[] = $entity->name;
            }

            $out['plan'][] = ['name' => $p['name'], 'email' => $p['email'], 'new' => ! $user, 'churches' => $links];
        }

        $out['unmatched'] = array_keys($unmatched);

        return $out;
    }

    /**
     * @param  array{pastors: list<array>, skipped: list<string>}  $parsed
     * @return array{created: int, linked: int}
     */
    public function apply(array $parsed, ?User $grantedBy = null): array
    {
        $created = 0;
        $linked = 0;

        // Each import is treated as the full master list: clear the "present"
        // flag everywhere, then set it again for everyone in this file.
        User::where('in_latest_import', true)->update(['in_latest_import' => false]);

        foreach ($parsed['pastors'] as $p) {
            $user = User::where('email', $p['email'])->first();

            if (! $user) {
                $user = new User([
                    'name'  => $p['name'] !== '' ? $p['name'] : $p['email'],
                    'email' => $p['email'],
                    'role'  => self::NEW_USER_ROLE,
                ]);
                // Not mass-assignable on purpose.
                $user->created_by_import = true;
                $user->save();
                $created++;
            }

            $user->in_latest_import = true;
            $user->save();

            foreach ($p['churches'] as $ref) {
                $entity = $this->resolveEntity($ref);

                if (! $entity || $user->entities()->where('entities.id', $entity->id)->wherePivot('role', 'admin')->exists()) {
                    continue;
                }

                $user->entities()->syncWithoutDetaching([
                    $entity->id => ['role' => 'admin', 'granted_by' => $grantedBy?->id],
                ]);
                $user->entities()->updateExistingPivot($entity->id, ['role' => 'admin']);
                $linked++;
            }

            $user->forgetEntityRoles();
        }

        return ['created' => $created, 'linked' => $linked];
    }

    /**
     * Accounts this importer created that were absent from the latest import
     * (e.g. pastors who have moved away), for optional cleanup.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function missingFromLatestImport(): \Illuminate\Support\Collection
    {
        return User::where('created_by_import', true)
            ->where('in_latest_import', false)
            ->withCount('entities')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at']);
    }

    private array $entityCache = [];

    private function resolveEntity(string $ref): ?Entity
    {
        return $this->entityCache[$ref] ??= Entity::where('org_mast_id', $ref)->first()
            ?? Entity::where('name', $ref)->first();
    }
}

<?php

namespace App\Services\DirectoryImport;

use App\Models\AdventistEntity;
use InvalidArgumentException;

/**
 * Parses an entity file (a `church:export` CSV, or the pastor-directory
 * data.json) into rows for `adventist_entities`, previews the effect, and
 * applies it.
 *
 * A row is ['where' => [...lookup keys], 'data' => [...fields]]. JSON rows only
 * carry the fields the file actually has, and null/empty values are dropped so
 * an import never blanks data (e.g. coordinates) that the scraper filled in.
 */
class EntityImporter
{
    public const CSV_COLUMNS = [
        'conference_code', 'om_entity_id', 'org_mast_id', 'entity_type', 'name',
        'address', 'city', 'state', 'zip', 'country', 'latitude', 'longitude',
        'phone', 'website', 'pastor', 'language', 'facebook', 'instagram',
        'twitter', 'youtube', 'extra',
    ];

    /** @return list<array{where: array, data: array}> */
    public function parse(string $path, string $conference = ''): array
    {
        $raw = file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        return str_starts_with(ltrim($raw, "\xEF\xBB\xBF \t\r\n"), '{')
            ? $this->parseJson($raw, strtoupper(trim($conference)))
            : $this->parseCsv($path);
    }

    private function parseJson(string $raw, string $conference): array
    {
        $json = json_decode($raw, true);

        if (! is_array($json) || ! isset($json['churchAddresses']) || ! is_array($json['churchAddresses'])) {
            throw new InvalidArgumentException('This JSON has no "churchAddresses" section.');
        }

        if ($conference === '') {
            throw new InvalidArgumentException('A conference code is required when importing JSON.');
        }

        $rows = [];

        foreach ($json['churchAddresses'] as $name => $c) {
            $orgCode = trim((string) ($c['orgCode'] ?? ''));

            if ($orgCode === '') {
                continue;
            }

            $extra = array_filter([
                'county'        => $c['county'] ?? null,
                'membership'    => $c['membership'] ?? null,
                'email'         => $c['email'] ?? null,
                'service_times' => $c['serviceTimes'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $rows[] = [
                'where' => ['conference_code' => $conference, 'org_mast_id' => $orgCode],
                'data'  => $this->withoutBlanks([
                    'name'    => (string) $name,
                    'address' => $c['street'] ?? null,
                    'city'    => $c['city'] ?? null,
                    'state'   => $c['state'] ?? null,
                    'zip'     => $c['zip'] ?? null,
                    'phone'   => $c['phone'] ?? null,
                    'website' => $c['website'] ?? null,
                    'extra'   => $extra ?: null,
                ]),
            ];
        }

        if (! $rows) {
            throw new InvalidArgumentException('No churches with an orgCode were found.');
        }

        return $rows;
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);
            throw new InvalidArgumentException('The CSV file has no header row.');
        }

        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $missing = array_diff(['conference_code', 'om_entity_id', 'name'], $header);

        if ($missing) {
            fclose($handle);
            throw new InvalidArgumentException('The CSV is missing columns: ' . implode(', ', $missing) . '.');
        }

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }

            $r = array_combine($header, $line);
            $r['extra'] = ($r['extra'] ?? '') !== '' ? json_decode($r['extra'], true) : null;

            $data = array_intersect_key($r, array_flip(self::CSV_COLUMNS));
            // Full-fidelity restore: empty cells become null (except the keys).
            foreach ($data as $k => $v) {
                if ($v === '') {
                    $data[$k] = null;
                }
            }
            unset($data['conference_code'], $data['om_entity_id']);

            $rows[] = [
                'where' => ['conference_code' => $r['conference_code'], 'om_entity_id' => $r['om_entity_id']],
                'data'  => $data,
            ];
        }

        fclose($handle);

        if (! $rows) {
            throw new InvalidArgumentException('No data rows found in the CSV.');
        }

        return $rows;
    }

    /**
     * @param  list<array{where: array, data: array}>  $rows
     * @return array{new: int, changed: int, unchanged: int, samples: list<array>}
     */
    public function preview(array $rows): array
    {
        $out = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'samples' => []];

        foreach ($rows as $row) {
            $existing = AdventistEntity::where($row['where'])->first();

            if (! $existing) {
                $out['new']++;
                $this->sample($out, 'new', $row['data']['name'] ?? '?', []);
                continue;
            }

            $changes = $this->changedFields($existing, $row['data']);

            if ($changes) {
                $out['changed']++;
                $this->sample($out, 'changed', $existing->name, $changes);
            } else {
                $out['unchanged']++;
            }
        }

        return $out;
    }

    /** @param  list<array{where: array, data: array}>  $rows */
    public function apply(array $rows): array
    {
        $saved = 0;

        foreach ($rows as $row) {
            $existing = AdventistEntity::where($row['where'])->first();
            $data = $row['data'];

            if ($existing) {
                // Merge JSON-sourced "extra" over what the scraper stored.
                if (isset($data['extra']) && is_array($existing->extra)) {
                    $data['extra'] = array_merge($existing->extra, $data['extra']);
                }
                $existing->update($data);
            } else {
                AdventistEntity::create($row['where'] + $data);
            }

            $saved++;
        }

        return ['saved' => $saved];
    }

    /** @return list<string> */
    private function changedFields(AdventistEntity $existing, array $data): array
    {
        $changed = [];

        foreach ($data as $field => $value) {
            $current = $existing->{$field};

            if ($field === 'extra' && is_array($value) && is_array($current)) {
                $value = array_merge($current, $value);
            }

            $a = is_array($current) ? json_encode($current) : (string) $current;
            $b = is_array($value) ? json_encode($value) : (string) $value;

            if (in_array($field, ['latitude', 'longitude'], true) && $a !== '' && $b !== '') {
                if (abs((float) $a - (float) $b) < 1e-7) {
                    continue;
                }
            }

            if ($a !== $b) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function withoutBlanks(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private function sample(array &$out, string $kind, string $name, array $fields): void
    {
        if (count($out['samples']) < 15) {
            $out['samples'][] = ['kind' => $kind, 'name' => $name, 'fields' => $fields];
        }
    }
}

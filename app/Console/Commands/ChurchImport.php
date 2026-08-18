<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use Illuminate\Console\Command;

class ChurchImport extends Command
{
    protected $signature = 'church:import
                            {path : Path to the CSV file to import}
                            {--fresh : Delete existing records for the conferences present in the CSV before importing}';

    protected $description = 'Import a previously exported CSV backup of Adventist entities';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('CSV file appears to be empty.');
            fclose($handle);
            return self::FAILURE;
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);

        if (empty($rows)) {
            $this->warn('No data rows found in CSV.');
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $conferences = collect($rows)->pluck('conference_code')->unique();
            foreach ($conferences as $conference) {
                $deleted = AdventistEntity::where('conference_code', $conference)->delete();
                $this->line("Deleted {$deleted} existing records for {$conference}.");
            }
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $saved = 0;
        foreach ($rows as $row) {
            $row['extra'] = isset($row['extra']) && $row['extra'] !== '' ? json_decode($row['extra'], true) : null;
            $row['latitude']  = $row['latitude'] !== '' ? (float) $row['latitude'] : null;
            $row['longitude'] = $row['longitude'] !== '' ? (float) $row['longitude'] : null;

            foreach ($row as $key => $value) {
                if ($value === '' && ! in_array($key, ['conference_code', 'om_entity_id', 'name'], true)) {
                    $row[$key] = null;
                }
            }

            AdventistEntity::updateOrCreate(
                ['conference_code' => $row['conference_code'], 'om_entity_id' => $row['om_entity_id']],
                $row
            );
            $saved++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Imported {$saved} of " . count($rows) . " entities.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;

class LanguageList extends Command
{
    protected $signature = 'language:list';

    protected $description = 'List all supported languages';

    public function handle(): int
    {
        $languages = Language::orderBy('name')->get();

        if ($languages->isEmpty()) {
            $this->info('No languages found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Abbreviation', 'Name', 'Native Name', 'Added'],
            $languages->map(fn ($l) => [
                $l->id,
                $l->abbreviation,
                $l->name,
                $l->native_name,
                $l->created_at->format('Y-m-d'),
            ])
        );

        $this->line('  Total: ' . $languages->count());
        return self::SUCCESS;
    }
}

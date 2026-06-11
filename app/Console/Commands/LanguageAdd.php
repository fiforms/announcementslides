<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class LanguageAdd extends Command
{
    protected $signature = 'language:add
                            {--abbreviation= : ISO abbreviation (e.g. es, fr, pt-BR)}
                            {--name= : English name (e.g. Spanish)}
                            {--native-name= : Name in the language itself (e.g. Español)}';

    protected $description = 'Add a supported language';

    public function handle(): int
    {
        $abbreviation = $this->option('abbreviation') ?? $this->ask('Abbreviation (e.g. es, fr, pt-BR)');
        $name         = $this->option('name')         ?? $this->ask('English name (e.g. Spanish)');
        $nativeName   = $this->option('native-name')  ?? $this->ask('Native name (e.g. Español)');

        $validator = Validator::make(
            ['abbreviation' => $abbreviation, 'name' => $name, 'native_name' => $nativeName],
            [
                'abbreviation' => 'required|string|max:10|unique:languages,abbreviation',
                'name'         => 'required|string|max:255',
                'native_name'  => 'required|string|max:255',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $language = Language::create([
            'abbreviation' => $abbreviation,
            'name'         => $name,
            'native_name'  => $nativeName,
        ]);

        $this->info("Language added: {$language->name} ({$language->abbreviation}) — {$language->native_name}");
        return self::SUCCESS;
    }
}

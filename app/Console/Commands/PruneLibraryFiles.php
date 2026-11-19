<?php

namespace App\Console\Commands;

use App\Support\LibraryFiles;
use Illuminate\Console\Command;

class PruneLibraryFiles extends Command
{
    protected $signature = 'library:prune {--min-age=600 : Keep files newer than this many seconds}';

    protected $description = 'Delete shared slide-library files that no library item or slide references';

    public function handle(LibraryFiles $files): int
    {
        $deleted = $files->sweep((int) $this->option('min-age'));
        $this->info(count($deleted) . ' file(s) deleted.');
        foreach ($deleted as $path) {
            $this->line("  {$path}");
        }

        return self::SUCCESS;
    }
}

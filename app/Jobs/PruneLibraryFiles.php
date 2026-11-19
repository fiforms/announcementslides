<?php

namespace App\Jobs;

use App\Support\LibraryFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Removes shared library files nothing references any more (see LibraryFiles::sweep). */
class PruneLibraryFiles implements ShouldQueue
{
    use Queueable;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('prune-library-files'))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(LibraryFiles $files): void
    {
        $files->sweep();
    }
}

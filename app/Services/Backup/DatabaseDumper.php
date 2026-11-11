<?php

namespace App\Services\Backup;

/** Writes the whole database to / loads it from a plain SQL file. */
interface DatabaseDumper
{
    /** Name recorded in the manifest; a restore refuses a different one. */
    public function driver(): string;

    /** @throws BackupException */
    public function dump(string $toFile): void;

    /** @throws BackupException */
    public function load(string $fromFile): void;
}

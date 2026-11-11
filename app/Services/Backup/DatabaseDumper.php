<?php

namespace App\Services\Backup;

/** Writes the whole database to / loads it from a plain SQL file. */
interface DatabaseDumper
{
    /** Name recorded in the manifest; a restore refuses a different one. */
    public function driver(): string;

    /**
     * Why backups cannot work on this install, or null when they can.
     * `code` is a stable key for the UI (driver | client); `message` is for the CLI.
     *
     * @return array{code: string, message: string}|null
     */
    public function unsupported(): ?array;

    /** @throws BackupException */
    public function dump(string $toFile): void;

    /** @throws BackupException */
    public function load(string $fromFile): void;
}

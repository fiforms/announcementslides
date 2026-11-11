<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * mysqldump / mysql from the image's mysql-client (MariaDB's client on Alpine,
 * which names the binaries mariadb-dump / mariadb). The password goes through
 * MYSQL_PWD so it never shows in the process list.
 */
class MysqlDumper implements DatabaseDumper
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function unsupported(): ?array
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'code'    => 'driver',
                'message' => "Backups need a MySQL database, but this install uses {$driver}. "
                    .($driver === 'sqlite' ? 'For SQLite, copy the database file (database/database.sqlite) and the storage directory.' : ''),
            ];
        }

        $finder = new ExecutableFinder();
        foreach ([['mariadb-dump', 'mysqldump'], ['mariadb', 'mysql']] as $pair) {
            if (! $finder->find($pair[0]) && ! $finder->find($pair[1])) {
                return [
                    'code'    => 'client',
                    'message' => 'The MySQL client tools (mysqldump and mysql, or mariadb-dump and mariadb) are not installed or not on the PATH of this process.',
                ];
            }
        }

        return null;
    }

    public function dump(string $toFile): void
    {
        [$binary, $isMaria] = $this->binary(['mariadb-dump', 'mysqldump']);

        $args = [
            $binary, ...$this->connectionArgs($isMaria),
            '--single-transaction', '--routines', '--no-tablespaces',
            '--result-file='.$toFile,
            $this->config()['database'],
        ];

        $this->run($args, null, 'Database dump');
    }

    public function load(string $fromFile): void
    {
        [$binary, $isMaria] = $this->binary(['mariadb', 'mysql']);

        $args = [$binary, ...$this->connectionArgs($isMaria), $this->config()['database']];

        $this->run($args, fopen($fromFile, 'rb'), 'Database restore');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return DB::connection()->getConfig();
    }

    /** @return list<string> */
    private function connectionArgs(bool $isMaria): array
    {
        $c = $this->config();

        $args = ['--host='.$c['host'], '--port='.($c['port'] ?? 3306), '--user='.$c['username']];

        if (! empty($c['unix_socket'])) {
            $args = ['--socket='.$c['unix_socket'], '--user='.$c['username']];
        }

        // MariaDB's client verifies the server certificate by default, which a
        // MySQL container's self-signed one fails. The link is the private
        // compose network; use BACKUP_CLIENT_OPTIONS to override.
        if ($isMaria) {
            $args[] = '--skip-ssl-verify-server-cert';
        }

        $extra = trim((string) config('backup.client_options'));
        if ($extra !== '') {
            array_push($args, ...preg_split('/\s+/', $extra));
        }

        return $args;
    }

    /** @return array{0: string, 1: bool} path and whether it is the MariaDB client */
    private function binary(array $candidates): array
    {
        $finder = new ExecutableFinder();

        foreach ($candidates as $name) {
            if ($path = $finder->find($name)) {
                return [$path, str_starts_with($name, 'mariadb')];
            }
        }

        throw new BackupException('The MySQL client tools ('.implode(' / ', $candidates).') are not installed.');
    }

    private function run(array $args, mixed $input, string $label): void
    {
        $process = new Process($args, null, ['MYSQL_PWD' => (string) ($this->config()['password'] ?? '')], $input, null);
        $process->run();

        if (is_resource($input)) {
            fclose($input);
        }

        if (! $process->isSuccessful()) {
            throw new BackupException($label.' failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}

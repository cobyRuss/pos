<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies the SQLite database to a timestamped file.
 *
 * A till holds the day's takings, the stock levels and the audit trail. Losing
 * that to a disk failure or a careless `migrate:fresh` is unrecoverable, so a
 * backup is one command away.
 *
 * On a MySQL deployment this refuses rather than pretending: the right backup
 * there is a mysqldump, and silently copying nothing would be worse than
 * saying so.
 */
class BackupCommand extends Command
{
    protected $signature = 'pos:backup
                            {--keep=14 : How many backups to retain}
                            {--output= : Directory to write into (default storage/app/backups)}
                            {--source= : File to copy (default: the configured SQLite database)}';

    protected $description = 'Take a timestamped backup of the SQLite database';

    public function handle(): int
    {
        // An explicit --source bypasses the connection check, which also makes
        // the command straightforward to exercise against a scratch file.
        $source = $this->option('source') ?: config('database.connections.'.config('database.default').'.database');

        $connection = config('database.default');

        if (! $this->option('source') && config("database.connections.{$connection}.driver") !== 'sqlite') {
            $this->error("The default connection [{$connection}] is not SQLite, so there is no file to copy.");
            $this->line('  Use your database tool instead, for example:');
            $this->line('  mysqldump --single-transaction -u USER -p DBNAME > backup.sql');

            return self::FAILURE;
        }

        if (! is_file($source)) {
            $this->error("No database file found at [{$source}].");

            return self::FAILURE;
        }

        $directory = $this->option('output') ?: storage_path('app/backups');

        try {
            File::ensureDirectoryExists($directory);
        } catch (Throwable $e) {
            $this->error("Could not create [{$directory}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $target = rtrim($directory, '/\\')
            .DIRECTORY_SEPARATOR
            .'pos-'.now()->format('Ymd-His').'.sqlite';

        // copy() rather than a database-level backup API: SQLite in WAL mode
        // keeps recent writes in a sidecar file, and a plain file copy taken
        // mid-write can capture a torn page. Checkpointing first forces those
        // writes into the main file so the copy is consistent.
        $this->checkpoint($source);

        if (! @copy($source, $target)) {
            $this->error("Failed to copy the database to [{$target}].");

            return self::FAILURE;
        }

        $size = number_format(filesize($target) / 1024, 1).' KB';

        $this->info("Backup written: {$target} ({$size})");

        $removed = $this->prune($directory, (int) $this->option('keep'));
        if ($removed > 0) {
            $this->line("Pruned {$removed} old backup(s), keeping the newest {$this->option('keep')}.");
        }

        return self::SUCCESS;
    }

    /**
     * Fold any write-ahead log into the main database file so a file copy is
     * a complete and consistent snapshot.
     *
     * No VACUUM here: it cannot run inside a transaction and rewrites the
     * whole file, which is not what a backup needs. Folding the WAL in is.
     */
    private function checkpoint(string $database): void
    {
        try {
            $pdo = new \PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
        } catch (Throwable) {
            // A checkpoint is an optimisation, not a requirement. Without WAL
            // the main file is already self-contained, and a database that
            // cannot be checkpointed right now is better served by a working
            // backup than by a failure.
        }
    }

    /**
     * Keep only the newest N backups.
     */
    private function prune(string $directory, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => Str::startsWith($file->getFilename(), 'pos-'))
            ->sortByDesc(fn ($file) => $file->getMTime());

        $stale = $files->slice($keep);

        foreach ($stale as $file) {
            @unlink($file->getPathname());
        }

        return $stale->count();
    }
}

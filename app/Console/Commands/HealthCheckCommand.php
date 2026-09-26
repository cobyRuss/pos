<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Reports anything about this installation that would be a problem in real
 * use, so it is discovered deliberately rather than on the day.
 *
 * Read-only: it inspects and reports, and never changes anything.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'pos:health';

    protected $description = 'Report configuration and data concerns before the till takes real money';

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $findings = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkDatabase();
        $this->checkAccounts();
        $this->checkCatalogue();
        $this->checkMigrations();

        $warnings = array_filter($this->findings, fn (array $f) => $f[0] === 'warn');
        $failures = array_filter($this->findings, fn (array $f) => $f[0] === 'fail');

        $this->newLine();

        if ($this->findings === []) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        foreach ($this->findings as [$level, $title, $detail]) {
            $label = match ($level) {
                'fail' => '<error>FAIL</error>',
                'warn' => '<comment>WARN</comment>',
                default => '<info>INFO</info>',
            };

            $this->line("  {$label}  {$title}");
            foreach (explode("\n", $detail) as $line) {
                $this->line("        {$line}");
            }
            $this->newLine();
        }

        if ($failures !== []) {
            $this->error(count($failures).' blocking issue(s) found.');

            return self::FAILURE;
        }

        $this->warn(count($warnings).' warning(s) found.');

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        if (config('app.debug')) {
            $this->findings[] = ['warn', 'APP_DEBUG is on', <<<'TXT'
                Debug mode shows stack traces and environment values to anyone
                who triggers an error. Set APP_DEBUG=false before real use.
                TXT];
        }

        if (! str_starts_with((string) config('app.url'), 'https') && config('app.env') === 'production') {
            $this->findings[] = ['warn', 'APP_URL is not HTTPS', <<<'TXT'
                Session cookies and staff credentials should travel over HTTPS.
                Put a TLS-terminating proxy in front of the app.
                TXT];
        }

        if (config('app.env') === 'production' && config('app.key') === '') {
            $this->findings[] = ['fail', 'No application key', 'Run: php artisan key:generate'];
        }
    }

    private function checkDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            $this->findings[] = ['warn', 'Running on SQLite', <<<'TXT'
                SQLite allows one writer at a time. A second register selling
                at the same moment will hit "database is locked" and a till
                will refuse the sale. This is fine for a single till; move to
                MySQL or PostgreSQL before adding a second one.
                TXT];
        }
    }

    private function checkAccounts(): void
    {
        $admins = User::role('admin')->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->findings[] = ['fail', 'No active administrator', <<<'TXT'
                Nobody can reach staff management, settings or reports.
                Create an admin account, or run: php artisan db:seed
                TXT];

            return;
        }

        $default = collect(['password', 'admin', 'admin123', '12345678', 'secret'])
            ->first(fn (string $guess) => $admins->contains(
                fn (User $user) => Hash::check($guess, $user->password),
            ));

        if ($default !== null) {
            $this->findings[] = ['fail', 'An administrator is using a well-known password', <<<'TXT'
                At least one admin account still has a default password. Change
                it in the app, or set SEED_ADMIN_PASSWORD and re-seed.
                TXT];
        }
    }

    private function checkCatalogue(): void
    {
        $outOfStock = Product::query()->where('stock', '<=', 0)->count();

        if ($outOfStock > 0) {
            $this->findings[] = ['info', "{$outOfStock} product(s) out of stock", <<<'TXT'
                These cannot be sold until restocked. Confirm that is intended
                rather than a stock count that was never taken.
                TXT];
        }
    }

    private function checkMigrations(): void
    {
        try {
            $pending = $this->getMigrationFiles();
        } catch (\Throwable) {
            // No migration table yet, or no filesystem access: nothing useful
            // to report, and certainly not a reason to alarm anyone.
            return;
        }

        if ($pending !== []) {
            $this->findings[] = ['fail', 'Migrations have not been run', implode(
                "\n",
                array_slice($pending, 0, 5).($pending === [] ? [] : [''.' and '.max(0, count($pending) - 5).' more'])
            )."\nRun: php artisan migrate --force"];
        }
    }

    /**
     * Migration filenames present on disk but absent from the migrations table.
     *
     * @return array<int, string>
     */
    private function getMigrationFiles(): array
    {
        $ran = $this->laravel['db']->table('migrations')->pluck('migration')->all();

        $onDisk = collect($this->laravel['migrator']->getMigrationFiles(database_path('migrations')))
            ->map(fn (string $path) => basename($path, '.php'))
            ->all();

        return array_values(array_diff($onDisk, $ran));
    }
}

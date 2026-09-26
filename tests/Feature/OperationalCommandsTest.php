<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OperationalCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('app/ops-test');
        File::deleteDirectory($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * A throwaway file to stand in for the database.
     *
     * The suite runs on an in-memory database, which has no file to copy, so
     * the command is pointed at a scratch file through --source. That keeps
     * the test honest without swapping the connection out from underneath
     * RefreshDatabase, which corrupts the transaction the suite relies on.
     */
    private function sourceFile(string $contents = 'sqlite-ish payload'): string
    {
        File::ensureDirectoryExists($this->scratch);
        $path = $this->scratch.'/source.sqlite';
        File::put($path, $contents);

        return $path;
    }

    public function test_backup_writes_a_timestamped_copy(): void
    {
        $target = $this->scratch.'/backups';

        $this->artisan('pos:backup', [
            '--source' => $this->sourceFile(),
            '--output' => $target,
        ])
            ->expectsOutputToContain('Backup written')
            ->assertSuccessful();

        $files = File::files($target);
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('pos-', $files[0]->getFilename());
        $this->assertStringEndsWith('.sqlite', $files[0]->getFilename());
        $this->assertSame('sqlite-ish payload', file_get_contents($files[0]->getPathname()));
    }

    public function test_backup_prunes_to_the_requested_retention(): void
    {
        $target = $this->scratch.'/backups';
        File::ensureDirectoryExists($target);

        foreach (['20260101-000000', '20260102-000000'] as $stamp) {
            File::put($target.'/pos-'.$stamp.'.sqlite', 'old');
            touch($target.'/pos-'.$stamp.'.sqlite', (int) strtotime($stamp));
        }

        $this->artisan('pos:backup', [
            '--source' => $this->sourceFile(),
            '--output' => $target,
            '--keep' => 1,
        ])
            ->expectsOutputToContain('Pruned')
            ->assertSuccessful();

        $this->assertCount(1, File::files($target), 'Old backups should have been pruned.');
    }

    public function test_backup_keeps_a_file_it_did_not_create(): void
    {
        $target = $this->scratch.'/backups';
        File::ensureDirectoryExists($target);
        File::put($target.'/notes.txt', 'do not delete me');

        $this->artisan('pos:backup', [
            '--source' => $this->sourceFile(),
            '--output' => $target,
        ])->assertSuccessful();

        $this->assertCount(2, File::files($target), 'Pruning must only touch its own pos-*.sqlite files.');
    }

    public function test_backup_reports_a_missing_database_file(): void
    {
        $this->artisan('pos:backup', ['--source' => $this->scratch.'/absent.sqlite'])
            ->expectsOutputToContain('No database file found')
            ->assertFailed();
    }

    /**
     * A genuine SQLite file, for the test that proves a backup is restorable.
     */
    private function realDatabaseFile(): string
    {
        File::ensureDirectoryExists($this->scratch);
        $path = $this->scratch.'/real.sqlite';
        @unlink($path);

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (name) VALUES ('Sam')");

        return $path;
    }

    public function test_a_backup_is_a_real_restorable_database(): void
    {
        $target = $this->scratch.'/backups';

        $this->artisan('pos:backup', [
            '--source' => $this->realDatabaseFile(),
            '--output' => $target,
        ])->assertSuccessful();

        $copy = File::files($target)[0]->getPathname();

        $pdo = new \PDO('sqlite:'.$copy);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // A file that exists but cannot be opened is not a backup.
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(
            'Sam',
            $pdo->query('SELECT name FROM users')->fetchColumn(),
            'The backup should contain the rows the source had.',
        );
    }

    public function test_health_reports_a_default_admin_password_as_blocking(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);
        $admin->forceFill(['password' => bcrypt('password')])->save();

        $this->artisan('pos:health')
            ->expectsOutputToContain('well-known password')
            ->assertFailed();
    }

    public function test_health_stops_reporting_a_default_password_once_it_is_changed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);
        $admin->forceFill(['password' => bcrypt('a-unique-passphrase-9182')])->save();

        $this->artisan('pos:health')
            ->doesntExpectOutputToContain('well-known password')
            ->assertSuccessful();
    }

    public function test_health_fails_when_there_is_no_administrator(): void
    {
        $this->artisan('pos:health')
            ->expectsOutputToContain('No active administrator')
            ->assertFailed();
    }

    /**
     * An administrator with a password that is not a well-known default.
     *
     * The factory password is literally "password", which pos:health
     * correctly treats as a blocking issue, so any test asserting a clean
     * health run has to set something else.
     */
    private function adminWithRealPassword(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);
        $admin->forceFill(['password' => bcrypt('a-unique-passphrase-9182')])->save();

        return $admin;
    }

    public function test_health_warns_about_sqlite(): void
    {
        $this->adminWithRealPassword();

        $this->artisan('pos:health')
            ->expectsOutputToContain('Running on SQLite')
            ->assertSuccessful();
    }

    public function test_health_warns_when_debug_is_on(): void
    {
        Config::set('app.debug', true);
        $this->adminWithRealPassword();

        $this->artisan('pos:health')
            ->expectsOutputToContain('APP_DEBUG is on')
            ->assertSuccessful();
    }

    public function test_health_notes_out_of_stock_products(): void
    {
        $this->adminWithRealPassword();
        Product::factory()->outOfStock()->create();

        $this->artisan('pos:health')
            ->expectsOutputToContain('out of stock')
            ->assertSuccessful();
    }

    public function test_health_reports_a_clean_installation_without_blocking_issues(): void
    {
        $this->adminWithRealPassword();

        Config::set('app.debug', false);

        $this->artisan('pos:health')
            ->doesntExpectOutputToContain('well-known password')
            ->doesntExpectOutputToContain('No active administrator')
            ->assertSuccessful();
    }

    public function test_the_printable_sales_report_renders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);

        $order = Order::factory()->create(['user_id' => $admin->id, 'total' => 42.00]);
        Setting::put(Setting::BUSINESS_NAME, 'Corner Coffee');

        $this->actingAs($admin)->get(route('admin.reports.print', [
            'report' => 'sales',
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]))
            ->assertOk()
            ->assertSee('Sales report')
            ->assertSee('Corner Coffee')
            ->assertSee($order->order_number)
            ->assertSee('42.00')
            // A print stylesheet, so paper gets a table not a screenshot.
            ->assertSee('@media print', false);
    }

    public function test_the_printable_inventory_report_renders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);
        Product::factory()->create(['name' => 'Flat White', 'stock' => 10, 'cost' => 2.00]);

        $this->actingAs($admin)->get(route('admin.reports.print', ['report' => 'inventory']))
            ->assertOk()
            ->assertSee('Inventory report')
            ->assertSee('Flat White')
            ->assertSee('20.00');
    }

    public function test_the_printable_sales_report_requires_dates(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);

        $this->actingAs($admin)
            ->get(route('admin.reports.print', ['report' => 'sales']))
            ->assertSessionHasErrors(['from', 'to']);
    }

    public function test_an_unknown_printable_report_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);

        $this->actingAs($admin)->get('/admin/reports/print/nonsense')->assertNotFound();
    }

    public function test_staff_cannot_reach_the_printable_reports(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($staff)
            ->get(route('admin.reports.print', ['report' => 'inventory']))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}

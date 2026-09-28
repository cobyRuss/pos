<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Full demo dataset: settings, accounts, catalog and 30 days of sales.
 *
 * Every seeder is idempotent, so `php artisan db:seed` can be re-run safely.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            UserSeeder::class,
            CatalogSeeder::class,
            DemoSalesSeeder::class,
            RestockSeeder::class,
        ]);
    }
}

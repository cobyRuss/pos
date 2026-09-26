<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A seeded install must be able to sign in, sell something and print a
 * receipt without any manual setup, so the order is deliberate: roles and
 * permissions first (the accounts depend on them), then the accounts, then the
 * store's own settings, then stock to sell.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            SettingsSeeder::class,
            CatalogueSeeder::class,
        ]);
    }
}

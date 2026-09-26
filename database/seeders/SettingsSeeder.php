<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Sensible starting values so the POS and the 80mm receipt are not blank on a
 * fresh install. Tax rate 0 means a seeded install does not silently overcharge
 * a real customer if it is ever pointed at live data.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            Setting::BUSINESS_NAME => env('SEED_BUSINESS_NAME', 'Corner POS'),
            Setting::BUSINESS_ADDRESS => env('SEED_BUSINESS_ADDRESS', ''),
            Setting::BUSINESS_PHONE => env('SEED_BUSINESS_PHONE', ''),
            Setting::CURRENCY_SYMBOL => env('SEED_CURRENCY_SYMBOL', '$'),
            Setting::TAX_RATE => env('SEED_TAX_RATE', '0'),
            Setting::RECEIPT_FOOTER => env('SEED_RECEIPT_FOOTER', 'Thank you for your purchase.'),
        ];

        foreach ($defaults as $key => $value) {
            // Only fill a gap: an administrator's saved value must survive a
            // re-seed, otherwise re-seeding silently reverts the shop's name
            // and tax rate on a working till.
            if (Setting::get($key) === null) {
                Setting::put($key, $value);
            }
        }
    }
}

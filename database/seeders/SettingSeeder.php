<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Baseline store configuration. Mirrors SettingController::DEFAULTS.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setMany([
            'store_name' => 'Demo Store',
            'store_address' => '12 Market Street, Downtown',
            'store_phone' => '+1 555 0100',
            'store_email' => 'hello@demostore.test',
            'currency_symbol' => '₱',
            'tax_rate' => '5',
            'receipt_footer' => 'Thank you for your purchase! Goods once sold are not returnable within 7 days.',
            'receipt_size' => '80mm',
            'low_stock_default' => '5',
            'refund_review_threshold' => '500',
            'refund_daily_limit' => '0',
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
        ]);

        Setting::flushCache();
    }
}

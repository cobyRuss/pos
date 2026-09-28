<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The store only takes cash and GCash, so the four-value payment enum is
 * narrowed to two.
 *
 * Historic rows are folded into a surviving method rather than dropped, because
 * the PaymentMethod cast throws on a value it does not know and that would take
 * the orders list, the receipt and the reports down with it. The old
 * "Mobile / E-Wallet" bucket maps onto GCash; card and "other" become cash.
 *
 * SQLite is only ever used by the test suite, which rebuilds the schema from
 * scratch on every run, and it cannot ALTER a column type - hence the guard.
 */
return new class extends Migration
{
    private const CASH_AND_GCASH = "ENUM('cash','gcash') NOT NULL DEFAULT 'cash'";

    private const ORIGINAL = "ENUM('cash','card','mobile','other') NOT NULL DEFAULT 'cash'";

    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("UPDATE `orders` SET `payment_method` = 'gcash' WHERE `payment_method` = 'mobile'");
        DB::statement("UPDATE `orders` SET `payment_method` = 'cash' WHERE `payment_method` NOT IN ('cash','gcash')");
        DB::statement('ALTER TABLE `orders` MODIFY `payment_method` '.self::CASH_AND_GCASH);

        DB::statement("UPDATE `refunds` SET `method` = 'gcash' WHERE `method` = 'mobile'");
        DB::statement("UPDATE `refunds` SET `method` = 'cash' WHERE `method` NOT IN ('cash','gcash')");
        DB::statement('ALTER TABLE `refunds` MODIFY `method` '.self::CASH_AND_GCASH);
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement('ALTER TABLE `orders` MODIFY `payment_method` '.self::ORIGINAL);
        DB::statement('ALTER TABLE `refunds` MODIFY `method` '.self::ORIGINAL);
    }

    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};

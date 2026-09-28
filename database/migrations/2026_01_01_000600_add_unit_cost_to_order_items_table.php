<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the buying (cost) price on every sold line.
     *
     * Without this the sales report would have to join the current product cost
     * against historic sales, which silently rewrites old profit figures as soon
     * as a price changes. Existing rows are backfilled from the product they
     * belong to so historic reports stay meaningful.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        // A correlated sub-select keeps this portable: SQLite has no UPDATE ... JOIN.
        DB::table('order_items')
            ->whereNotNull('product_id')
            ->update([
                'unit_cost' => DB::raw('(SELECT cost_price FROM products WHERE products.id = order_items.product_id)'),
            ]);

        DB::table('order_items')->whereNull('unit_cost')->update(['unit_cost' => 0]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

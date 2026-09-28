<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the SKU / barcode attributes.
     *
     * Products are identified by name and category only: the till never scanned
     * a barcode, and the unique SKU column meant two staff could not add the
     * same product twice without first inventing a code. order_items.sku goes
     * with it so no table keeps a column nothing writes any more.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropUnique(['barcode']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sku', 'barcode']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('name');
            $table->string('barcode')->nullable()->after('sku');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('product_name');
        });
    }
};

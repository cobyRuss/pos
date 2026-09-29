<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Put the barcode back on the product.
     *
     * Migration 000700 dropped this column on the grounds that the till never
     * scanned a barcode. That is no longer true: the store now scans products
     * with the device camera at the till, and a scanned number is matched
     * against this column to find the product. It is nullable and unique, so
     * produce and repacked goods with no printed code simply carry no barcode
     * and are still found by name - scanning is an accelerator, never the only
     * way in.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 32)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};

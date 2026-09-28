<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties the stock log to the lot a movement came from.
 *
 * `expiry_date` is a snapshot taken when the movement was written, so a lot
 * record that is later edited or deleted does not rewrite history: the log
 * still shows which date those units were sold under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // nullOnDelete, not cascade: a movement is audit history and must
            // outlive the lot row it referenced.
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('product_batches')->nullOnDelete();
            $table->date('expiry_date')->nullable()->after('batch_id');

            $table->index(['product_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('expiry_date');
            $table->dropIndex(['product_id', 'batch_id']);
        });
    }
};

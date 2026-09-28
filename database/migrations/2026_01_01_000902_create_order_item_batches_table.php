<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which lot each sold line actually came from, and how much of it is still out.
 *
 * A first-expiry-first-out sale can draw from several lots, so a single
 * batch_id on the order item cannot describe it. Recording the split here is
 * what lets a cancellation or refund put the units back on the lots they left,
 * instead of guessing and corrupting the expiry dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();

            // nullOnDelete: if the lot is removed later, the row stays as a record
            // of where the units went. Returns then fall back to the general lot.
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();

            // Units still out on this lot. Refunds and cancellations decrement it,
            // so a partial return puts back exactly what it should.
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->index(['order_item_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_batches');
    }
};

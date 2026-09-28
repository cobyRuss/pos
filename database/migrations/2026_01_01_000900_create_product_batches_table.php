<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-delivery stock lots, so each receipt carries its own expiration date.
 *
 * A product used to hold a single flat `stock` integer, which cannot express
 * "40 cans of Coke, 30 of which expire in March". This table splits that number
 * into lots. `products.stock` stays in place and becomes the cached sum of the
 * lots below, so every existing screen and report keeps reading one number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // The supplier's own reference. Optional: a product may hold a
            // single undated lot, which is what the backfill below creates.
            $table->string('batch_no')->nullable();

            // Null means "does not expire", which is what non-perishables get.
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // FEFO consumption reads the lots for a product in expiry order, and
            // the expiry reports scan the whole table by date.
            $table->index(['product_id', 'expiry_date'], 'product_batches_product_expiry_index');
            $table->index('expiry_date');
        });

        // Adopt the existing flat stock: one undated lot per product, holding
        // whatever is on hand. Nothing is lost and products.stock stays truthful.
        DB::table('products')->where('stock', '>', 0)->orderBy('id')->each(function (object $product) {
            DB::table('product_batches')->insert([
                'product_id' => $product->id,
                'batch_no' => null,
                'expiry_date' => null,
                'quantity' => (int) $product->stock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};

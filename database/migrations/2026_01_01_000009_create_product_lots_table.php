<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opt-in: a shop that sells coffee does not want to create batches for
        // every bag of beans, and one that sells sandwiches cannot track them
        // without batches. Only products that set this are lot-allocated.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_expiry')->default(false)->after('cost');

            // How close to expiry counts as "use soon" for this product. A
            // bakery's pastries and a brewery's bottles are not on the same
            // clock, so it is per product rather than a global constant.
            $table->unsignedInteger('expiry_warning_days')->default(3)->after('tracks_expiry');
        });

        Schema::create('product_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Batch reference from the supplier, e.g. "DLV-4471" or a bake date.
            $table->string('code', 64);

            // null means "no expiry recorded", which sorts last under FEFO: a
            // lot with no known date is treated as the longest-lived, not the
            // most urgent.
            $table->date('expires_at')->nullable();

            $table->unsignedInteger('quantity')->default(0);

            // Captured per batch, because the same product can be bought at
            // different prices and the margin of a sale is only as good as the
            // cost that was true when it happened.
            $table->decimal('cost', 12, 2)->nullable();

            $table->date('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'expires_at']);
            $table->index('expires_at');
        });

        // Which batch each sold unit came from. A single line can draw from
        // several lots, so this cannot live on order_items as a single
        // lot_id. It is also what makes a return traceable to the batch the
        // goods actually left from.
        Schema::create('order_item_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('product_lots')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index('order_item_id');
            $table->index('lot_id');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            // nullable: movements for products that do not track expiry have
            // no batch, and the log must keep reconciling for them.
            $table->foreignId('lot_id')->nullable()->after('product_id')
                ->constrained('product_lots')->nullOnDelete();
        });

        $this->backfillLegacyLots();
    }

    /**
     * Give every product that already holds stock a single undated lot.
     *
     * Without this the existing stock would become invisible: the source of
     * truth moves to product_lots, so a product with 20 units and no lot would
     * look like zero. The lot is marked LEGACY and left without an expiry, so
     * behaviour for existing products is unchanged.
     */
    private function backfillLegacyLots(): void
    {
        DB::table('products')
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->each(function (object $product): void {
                DB::table('product_lots')->insert([
                    'product_id' => $product->id,
                    'code' => 'LEGACY',
                    'expires_at' => null,
                    'quantity' => $product->stock,
                    'cost' => null,
                    'received_at' => null,
                    'notes' => 'Migrated from the pre-batch stock count.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lot_id');
        });

        Schema::dropIfExists('order_item_lots');
        Schema::dropIfExists('product_lots');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tracks_expiry', 'expiry_warning_days']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('total_amount', 12, 2)->default(0);
            // How the money was given back. A refund is money leaving the till,
            // so without this a card or e-wallet payout cannot be reconciled
            // against the payment provider later.
            $table->enum('method', ['cash', 'card', 'digital'])->default('cash');
            $table->string('reference_no', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('user_id');
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->enum('condition', ['restockable', 'damaged'])->default('restockable');
            $table->boolean('restocked')->default(false);
            $table->timestamps();

            $table->index('refund_id');
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};

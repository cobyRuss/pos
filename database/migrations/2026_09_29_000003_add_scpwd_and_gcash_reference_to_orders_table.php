<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Senior citizen / PWD discount, and the GCash reference.
     *
     * This is not a promo engine. The till still has no discount route, no Apply
     * button and no way to discount a single line: the cart remains
     * subtotal + tax. What is added is a statutory discount a cashier must be
     * able to honour at the counter, plus the evidence that it was honoured.
     *
     * The entitlement is a legal one in the Philippines, so the alternative to
     * recording it is worse than either option: the cashier either refuses a
     * discount the customer is entitled to, or hands it over and takes the cash
     * out of the drawer with no record that it happened. Storing the name and ID
     * number makes the claim checkable and the discount reportable.
     *
     * The existing orders.discount_* columns now carry a real value for these
     * rows instead of the zeros every new order used to be written with. They
     * are still not a general discount mechanism.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('scpwd_applied')->default(false)->after('discount_amount');
            $table->string('scpwd_name')->nullable()->after('scpwd_applied');
            $table->string('scpwd_id_type', 40)->nullable()->after('scpwd_name');
            $table->string('scpwd_id_number', 60)->nullable()->after('scpwd_id_type');

            // The GCash reference the cashier reads off the customer's phone.
            // Cash sales have none, which is why it is nullable.
            $table->string('gcash_reference', 60)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'scpwd_applied',
                'scpwd_name',
                'scpwd_id_type',
                'scpwd_id_number',
                'gcash_reference',
            ]);
        });
    }
};

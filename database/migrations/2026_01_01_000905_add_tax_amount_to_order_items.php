<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze each order line's share of the tax at the moment of sale.
 *
 * A refund has to hand back what the customer actually paid, tax included. Until
 * now only the order header carried `tax_amount`, so a full refund could only be
 * priced against the pre-tax `line_total` - which left a permanent sliver on
 * every refunded sale and made `net_total` never reach zero.
 *
 * Allocating the order's tax to its lines in proportion to `line_total`, and
 * freezing it on the line, has the same property the codebase already relies on
 * for `unit_cost`: changing `tax_rate` tomorrow must never rewrite what a refund
 * pays out today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('line_total');
        });

        // Backfill existing lines. The last line of each order absorbs the
        // rounding remainder so the shares sum to exactly `orders.tax_amount`.
        DB::table('orders')
            ->where('tax_amount', '>', 0)
            ->orderBy('id')
            ->select('id', 'subtotal', 'tax_amount')
            ->each(function (object $order) {
                $subtotal = (float) $order->subtotal;
                $tax = (float) $order->tax_amount;

                if ($subtotal <= 0) {
                    return;
                }

                $items = DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->orderBy('id')
                    ->get(['id', 'line_total']);

                if ($items->isEmpty()) {
                    return;
                }

                $allocated = 0.0;
                $lastId = $items->last()->id;

                foreach ($items as $item) {
                    $share = (int) $item->id === (int) $lastId
                        ? round($tax - $allocated, 2)
                        : round($tax * ((float) $item->line_total / $subtotal), 2);

                    $allocated += $share;

                    DB::table('order_items')
                        ->where('id', $item->id)
                        ->update(['tax_amount' => $share]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });
    }
};

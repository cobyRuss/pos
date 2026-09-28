<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security columns staff-driven refunds need.
 *
 * The refund ledger was originally written assuming only an administrator would
 * ever touch it. Cashiers now process refunds in the owner's absence, so each
 * row carries the extra evidence an admin needs to settle an argument from the
 * report alone:
 *
 *  - `reason_code`   a fixed, comparable reason. `reason` stays as free text for
 *                    the detail, and is now optional everywhere except `other`.
 *  - `idempotency_key` collapses a retried or double-clicked submission back to
 *                    the refund that already exists instead of paying twice.
 *  - `review_required` flags a high-value refund. It never blocks the till - the
 *                    store cannot afford to stall the counter waiting on a manager
 *                    who may be asleep - it just puts the row at the top of the
 *                    reconciliation report.
 *  - `tax_amount`    the tax share being handed back, so `refunds.tax_amount`
 *                    sums to `orders.tax_amount` once everything is returned.
 *  - `reviewed_by` / `reviewed_at`  closes the loop: the admin has seen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->decimal('tax_amount', 14, 2)->default(0)->after('amount');

            // Nullable, not defaulted: rows written before this migration have no
            // code and should not be retroactively filed under a reason.
            $table->string('reason_code', 32)->nullable()->after('status');

            // Unique so a replayed request hits the index rather than paying out.
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->boolean('review_required')->default(false)->after('note');
            $table->timestamp('reviewed_at')->nullable()->after('review_required');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('reviewed_at');

            // The reconciliation report groups refunds by cashier and by day.
            $table->index(['user_id', 'refunded_at'], 'refunds_user_refunded_idx');
            $table->index('review_required');
        });

        // `reason` used to be the only, required, free-text field. It is now the
        // optional detail line underneath the required `reason_code`, so the
        // cashier is not forced to type a sentence to pick "damaged item".
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->change();
        });

        // Per-line tax. Without it, clearing a line in a second instalment
        // cannot work out what tax is still outstanding, and the order would
        // never reconcile to zero.
        Schema::table('refund_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('amount');
        });

        // One notification row per (refund, channel) so a retried alert updates
        // the existing attempt instead of spamming the owner's phone.
        Schema::create('refund_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['refund_id', 'channel']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_notifications');

        Schema::table('refund_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex('refunds_user_refunded_idx');
            $table->dropIndex(['review_required']);
            $table->dropUnique(['idempotency_key']);

            $table->dropColumn([
                'tax_amount',
                'reason_code',
                'idempotency_key',
                'review_required',
                'reviewed_at',
                'reviewed_by',
            ]);
        });
    }
};

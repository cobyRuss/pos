<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A counted cash drawer.
     *
     * The refund reconciliation report has always answered "what *should* be in
     * the drawer", which is a derived figure: it can tell a cashier skimmed, but
     * only after the fact, and it cannot tell the difference between skimming
     * and a miscount. A session closes that gap. The cashier counts the drawer
     * at the end of a shift, enters what is actually there, and the difference
     * against the expected figure is recorded with a reason.
     *
     * The expected figure is *not* stored. It is derived from the orders and
     * refunds that fall inside the session window, so editing a historic order
     * or filing a late refund corrects the past session's arithmetic rather than
     * leaving it quietly stale.
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_float', 14, 2)->default(0);

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->string('variance_reason', 255)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // No unique index guards "one open drawer per cashier": closed_at is
            // NULL while a session is open, and MySQL treats every NULL as
            // distinct in a unique index, so the constraint would not fire.
            // DrawerService::open() enforces it inside a transaction instead,
            // which is the right level of machinery for a single till.
            $table->index(['user_id', 'closed_at']);
            $table->index(['opened_at', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};

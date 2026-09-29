<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A counter per document type per period.
     *
     * Receipt numbers have to run consecutively, which rules out the random hex
     * order numbers the app used before. A row here holds the next number for a
     * scope (currently "receipt") on a given day, and the counter is advanced
     * under a row lock inside the same transaction that writes the order.
     *
     * The consequence is worth stating plainly: a number is only consumed if the
     * order it belongs to actually commits, so a failed checkout leaves no gap
     * and no number is ever reused. That is a stronger guarantee than a real
     * terminal, where numbers are handed to the machine up front and a Z-report
     * reconciles the unused range at the end of the day.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40);
            $table->date('period');
            $table->unsignedInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['scope', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};

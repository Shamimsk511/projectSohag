<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash a field officer handed to the office. Not a ledger row (the money stays in the NGO); it
     * only lowers the officer's cash in hand. Rows are immutable; recording one confirms it.
     */
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_officer_id')->constrained('users')->restrictOnDelete();
            // Poisha, always > 0.
            $table->bigInteger('amount');
            // Dhaka business date.
            $table->date('handover_date');
            $table->string('note', 500)->nullable();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['field_officer_id', 'handover_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
    }
};

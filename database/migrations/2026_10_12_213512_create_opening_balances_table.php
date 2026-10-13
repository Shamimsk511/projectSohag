<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Office cash on hand at go-live. At most one row (enforced by OpeningBalanceService), immutable,
     * not a ledger row: it carries no party, account or receipt.
     */
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            // Dhaka business date; cash is counted from the start of this day.
            $table->date('as_of');
            // Poisha, always > 0.
            $table->unsignedBigInteger('amount');
            $table->string('note', 500)->nullable();
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};

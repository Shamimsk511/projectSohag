<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('account_number', 20)->unique();
            $table->string('account_type', 20);
            // Poisha. What admin says is due on a loan; null for savings. Changed only via account_adjustments.
            $table->bigInteger('contract_amount')->nullable();
            // Dhaka business dates. A null closed_at means the account is active.
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['party_id', 'account_type']);
            $table->index(['account_type', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

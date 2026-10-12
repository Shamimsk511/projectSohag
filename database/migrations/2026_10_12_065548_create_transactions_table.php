<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single ledger. Rows are immutable (no updated_at, no soft deletes); mistakes are fixed by a
     * reversal row pointing at the original. Only TransactionService writes here.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('field_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            // Foreign key added with collection_schedules (Phase 4).
            $table->unsignedBigInteger('schedule_id')->nullable()->index();
            $table->string('type', 30);
            // Poisha, always > 0; direction comes from type.
            $table->bigInteger('amount');
            $table->string('payment_method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->string('receipt_no', 30)->nullable()->unique();
            $table->string('description', 500)->nullable();
            $table->date('transaction_date');
            $table->uuid('client_uuid')->nullable()->unique();
            // Unique: a transaction can be reversed at most once.
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_date');
            $table->index(['account_id', 'transaction_date']);
            $table->index(['field_officer_id', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

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
        Schema::create('collection_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            // Officer assigned to the party when the row was generated; null if the party had none.
            $table->foreignId('field_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('due_date');
            // Poisha.
            $table->bigInteger('expected_amount');
            $table->string('status', 20)->default('pending');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'due_date']);
            $table->index(['field_officer_id', 'due_date', 'status']);
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_schedules');
    }
};

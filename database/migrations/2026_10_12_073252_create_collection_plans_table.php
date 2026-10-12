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
        Schema::create('collection_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->string('frequency', 20);
            // Poisha expected per collection day.
            $table->bigInteger('installment_amount');
            // Daily plans only: weekdays to collect on (0 = Sunday .. 6 = Saturday).
            $table->json('weekdays')->nullable();
            // Dhaka business dates. Weekly/monthly plans repeat on starts_on's weekday/day of month.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_plans');
    }
};

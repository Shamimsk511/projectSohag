<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gap-free counters for human-facing numbers (account numbers, receipts). See SequenceService.
     */
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->unsignedBigInteger('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};

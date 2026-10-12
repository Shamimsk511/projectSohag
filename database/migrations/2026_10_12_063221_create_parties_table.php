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
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            // Filled from the id inside the create transaction, hence nullable.
            $table->string('party_code', 20)->nullable()->unique();
            $table->string('name');
            $table->string('father_or_husband_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('phone', 20)->index();
            $table->string('national_id', 20)->nullable()->unique();
            $table->string('address', 500);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('nominee_name')->nullable();
            $table->string('nominee_relation', 100)->nullable();
            $table->string('nominee_phone', 20)->nullable();
            $table->string('nominee_national_id', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('name');
            $table->index(['area_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};

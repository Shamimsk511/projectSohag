<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Office heads (admission fee, salary, bills...) classify office income and expense. `kind` is the
     * office transaction type the head may be used with.
     */
    public function up(): void
    {
        Schema::create('office_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('office_head_id')->nullable()->after('schedule_id')->constrained('office_heads');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_head_id');
        });

        Schema::dropIfExists('office_heads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The collection an automatic handover was written for (null = recorded by hand). The handover
     * stops counting when that collection is reversed.
     */
    public function up(): void
    {
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->unique()->after('field_officer_id')
                ->constrained('transactions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }
};

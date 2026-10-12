<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null closed_by = closed by the nightly `days:close` command.
     */
    public function up(): void
    {
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Fails while system closings exist (they have no user to fall back to).
     */
    public function down(): void
    {
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saving account's agreed amount can be set for the first time later, so there is no old value.
     */
    public function up(): void
    {
        Schema::table('account_adjustments', function (Blueprint $table) {
            $table->bigInteger('old_amount')->nullable()->change();
        });
    }

    /**
     * Fails while first-set rows (old_amount null) exist; delete or backfill them first.
     */
    public function down(): void
    {
        Schema::table('account_adjustments', function (Blueprint $table) {
            $table->bigInteger('old_amount')->nullable(false)->change();
        });
    }
};

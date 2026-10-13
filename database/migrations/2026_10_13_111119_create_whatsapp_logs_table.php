<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Taps on a WhatsApp button (the chat was opened; sending is not confirmed). Immutable, not a ledger row.
     */
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('collection_schedules')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('phone', 20);
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['schedule_id', 'created_at']);
            $table->index(['party_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};

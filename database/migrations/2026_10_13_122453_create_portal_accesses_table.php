<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One secret member-portal link per party, with the member's PIN. Secrets are hashed or encrypted.
     */
    public function up(): void
    {
        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('pin_hash');
            $table->boolean('must_change_pin')->default(true);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accesses');
    }
};

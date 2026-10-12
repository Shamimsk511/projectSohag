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
        Schema::create('party_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('field_officer_id')->constrained('users')->restrictOnDelete();
            // Dhaka business dates. A null assigned_until marks the party's one active assignment.
            $table->date('assigned_from');
            $table->date('assigned_until')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['party_id', 'assigned_until']);
            $table->index(['field_officer_id', 'assigned_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party_assignments');
    }
};

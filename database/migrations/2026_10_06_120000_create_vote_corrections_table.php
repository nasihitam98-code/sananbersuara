<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengajuan koreksi/pembatalan suara Mode Resmi (bagian 8): diajukan Admin RT, diputuskan Super Admin.
     * Tidak menyimpan pilihan kandidat.
     */
    public function up(): void
    {
        Schema::create('vote_corrections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->uuid('vote_id');
            $table->foreign('vote_id')->references('id')->on('votes')->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained()->restrictOnDelete();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            $table->string('status', 20);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['vote_id', 'pending_key'], 'corrections_one_pending_per_vote');
            $table->index(['election_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vote_corrections');
    }
};

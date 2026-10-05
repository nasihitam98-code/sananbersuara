<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suara. Sengaja tanpa timestamp dan memakai UUID acak sebagai kunci,
     * supaya urutan/waktu tidak bisa dicocokkan dengan catatan kehadiran.
     *
     * voter_link (Mode Dadakan) = HMAC(kunci server, peserta + surat suara + putaran),
     * hanya untuk "Pulihkan Hak Pilih", dan dikosongkan saat pemilihan ditutup.
     */
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wave_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('SAH');
            $table->char('voter_link', 64)->nullable();

            $table->index(['round_id', 'ballot_id', 'status']);
            $table->index(['round_id', 'ballot_id', 'voter_link']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};

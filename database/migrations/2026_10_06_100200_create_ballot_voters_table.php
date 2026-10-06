<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot hak pilih per surat suara, dibekukan saat pemilihan dimulai.
     * RT disalin dari data pemilih saat snapshot (K09: pindah RT setelahnya tidak mengubah hak pilih).
     */
    public function up(): void
    {
        Schema::create('ballot_voters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('added_during_live')->default(false);
            $table->string('added_reason', 40)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 190)->nullable();
            $table->timestamps();

            $table->unique(['ballot_id', 'voter_id']);
            $table->index(['ballot_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ballot_voters');
    }
};

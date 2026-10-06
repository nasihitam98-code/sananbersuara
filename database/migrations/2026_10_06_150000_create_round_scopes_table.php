<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cakupan putaran lanjutan (K22). Putaran 1 tidak punya baris = semua surat suara dan semua calon.
     * Putaran berikutnya hanya untuk surat suara/RT dan calon yang ditetapkan panitia.
     */
    public function up(): void
    {
        Schema::create('round_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();

            $table->index(['round_id', 'ballot_id']);
        });

        Schema::create('round_candidates', function (Blueprint $table) {
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->primary(['round_id', 'candidate_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('round_candidates');
        Schema::dropIfExists('round_scopes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan "sudah memilih" per peserta, per surat suara, per putaran.
     *
     * active_key bernilai 1 selama catatan berlaku dan NULL setelah dibatalkan,
     * sehingga unique index menjamin hanya ada satu catatan aktif (anti pemilihan ganda)
     * tanpa menghapus riwayat.
     */
    public function up(): void
    {
        Schema::create('attendee_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wave_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_assisted')->default(false);
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['attendee_id', 'ballot_id', 'round_id', 'active_key'], 'participation_one_active');
            $table->index(['round_id', 'ballot_id', 'active_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendee_participations');
    }
};

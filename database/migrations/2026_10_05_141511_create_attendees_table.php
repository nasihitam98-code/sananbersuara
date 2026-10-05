<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peserta hadir Mode Dadakan (didata di pintu). Tidak terhubung ke tabel suara.
     */
    public function up(): void
    {
        Schema::create('attendees', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq_no');
            $table->string('name');
            $table->string('name_search');
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('pin_hash', 64);
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_at')->nullable();
            $table->timestamp('pin_issued_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['election_id', 'seq_no']);
            $table->index(['election_id', 'name_search']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendees');
    }
};

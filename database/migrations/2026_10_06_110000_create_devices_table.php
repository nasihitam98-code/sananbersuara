<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slot perangkat per pemilihan per RT (K14): satu MEJA (nomor 0) dan beberapa BILIK (1..N).
     *
     * Token pemasangan dan rahasia sesi hanya disimpan sebagai hash. Laptop yang dilepas
     * langsung kehilangan akses karena hash rahasianya dihapus.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->unsignedTinyInteger('number');
            $table->string('label', 120)->nullable();
            $table->char('pairing_token_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_token_expires_at')->nullable();
            $table->char('session_secret_hash', 64)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 40)->nullable();
            $table->timestamp('last_permit_ended_at')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'unit_id', 'kind', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

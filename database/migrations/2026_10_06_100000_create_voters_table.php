<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemilih terdaftar per RT (Mode Resmi).
     *
     * NIK tidak disimpan penuh (K11): hanya HMAC berkunci untuk deteksi duplikat + 4 digit terakhir
     * untuk tampilan termasking. Nomor HP disimpan terenkripsi.
     */
    public function up(): void
    {
        Schema::create('voters', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('voter_number', 20)->unique()->nullable();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_search');
            $table->string('address', 190);
            $table->char('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->char('nik_hash', 64)->nullable()->unique();
            $table->char('nik_last4', 4)->nullable();
            $table->text('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('inactive_reason', 190)->nullable();
            $table->boolean('added_during_live')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'name_search']);
            $table->index(['name_search', 'birth_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voters');
    }
};

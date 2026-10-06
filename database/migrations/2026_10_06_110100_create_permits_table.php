<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Izin memilih (Mode Resmi): satu pemilih ke satu bilik.
     *
     * active_voter_id / active_device_id hanya terisi selama izin aktif; unique index-nya menjamin
     * satu pemilih tidak bisa diizinkan ke dua bilik dan satu bilik tidak bisa menerima dua izin,
     * walaupun dua petugas menekan tombol pada detik yang sama.
     */
    public function up(): void
    {
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('desk_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->timestamp('granted_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('active_voter_id')->nullable();
            $table->unsignedBigInteger('active_device_id')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'active_voter_id'], 'permits_one_active_per_voter');
            $table->unique('active_device_id', 'permits_one_active_per_device');
            $table->index(['election_id', 'status']);
        });

        Schema::table('votes', function (Blueprint $table) {
            $table->foreignId('permit_id')->nullable()->after('voter_id')->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->after('permit_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropConstrainedForeignId('permit_id');
        });

        Schema::dropIfExists('permits');
    }
};

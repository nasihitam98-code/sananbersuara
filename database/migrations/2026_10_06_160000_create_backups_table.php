<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat backup (K27): tanggal, ukuran, status, pembuat.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('filename', 190)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->string('status', 20);
            $table->string('trigger', 30);
            $table->boolean('offsite_copied')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};

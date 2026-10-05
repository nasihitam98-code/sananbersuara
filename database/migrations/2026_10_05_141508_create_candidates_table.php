<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kandidat. photo_key adalah nama dasar acak file foto (tiga ukuran).
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name');
            $table->string('photo_key', 64)->nullable();
            $table->string('status', 20)->default('AKTIF');
            $table->timestamps();

            $table->index(['ballot_id', 'unit_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};

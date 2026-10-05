<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar RT untuk surat suara bercakupan "RT tertentu saja".
     */
    public function up(): void
    {
        Schema::create('ballot_unit', function (Blueprint $table) {
            $table->foreignId('ballot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->primary(['ballot_id', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ballot_unit');
    }
};

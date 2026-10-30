<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Posisi bagian foto yang terlihat di bingkai (0 = paling atas, 100 = paling bawah). Foto disimpan utuh, jadi
     * posisi bisa digeser kapan saja tanpa unggah ulang (keputusan pemilik 2026-10-30).
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->unsignedTinyInteger('photo_focus')->default(0)->after('photo_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('photo_focus');
        });
    }
};

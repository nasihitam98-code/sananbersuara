<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asal RT calon (mis. calon RW perwakilan RT 03). Hanya keterangan tampilan; berbeda dengan
     * unit_id yang menentukan pemilih RT mana yang melihat calon pada surat suara per RT.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('origin_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_unit_id');
        });
    }
};

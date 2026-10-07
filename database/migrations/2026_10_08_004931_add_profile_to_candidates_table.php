<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil calon (opsional) yang bisa dibaca warga sebelum memilih: visi singkat dan misi per baris.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('vision', 500)->nullable()->after('name');
            $table->text('mission')->nullable()->after('vision');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['vision', 'mission']);
        });
    }
};

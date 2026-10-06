<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kebijakan retensi data (K26).
     */
    public function up(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('closed_at');
            $table->timestamp('personal_data_purged_at')->nullable()->after('vote_links_destroyed_at');
        });

        // Status "sudah memilih" tanpa pilihan, dipakai setelah keterkaitan suara dihapus.
        Schema::table('ballot_voters', function (Blueprint $table) {
            $table->timestamp('voted_at')->nullable()->after('revoked_reason');
        });

        // Agar keterkaitan pengajuan koreksi -> suara bisa dihapus tanpa menghapus catatan pengajuan.
        Schema::table('vote_corrections', function (Blueprint $table) {
            $table->uuid('vote_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'personal_data_purged_at']);
        });

        Schema::table('ballot_voters', function (Blueprint $table) {
            $table->dropColumn('voted_at');
        });
    }
};

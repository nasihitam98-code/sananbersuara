<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mode Resmi menyimpan keterkaitan pemilih-suara (hanya untuk Detail Suara Super Admin setelah ditutup).
     * Unique (surat suara, putaran, pemilih, active_key) menjamin satu suara sah per pemilih;
     * active_key menjadi NULL saat suara dibatalkan sehingga pemilih bisa memilih ulang.
     */
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->foreignId('voter_id')->nullable()->after('candidate_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('active_key')->nullable()->default(1)->after('status');

            $table->unique(['ballot_id', 'round_id', 'voter_id', 'active_key'], 'votes_one_active_per_voter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropUnique('votes_one_active_per_voter');
            $table->dropConstrainedForeignId('voter_id');
            $table->dropColumn('active_key');
        });
    }
};

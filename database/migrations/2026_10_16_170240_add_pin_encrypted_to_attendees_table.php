<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIN peserta disimpan terenkripsi (APP_KEY) agar tampil di tabel Meja Pintu dan Ruang Kendali (keputusan panitia
     * 16 Okt 2026). Verifikasi tetap memakai pin_hash; kolom ini dikosongkan saat pemilihan ditutup atau dibatalkan.
     */
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->text('pin_encrypted')->nullable()->after('pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn('pin_encrypted');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token pemasangan yang belum dipakai disimpan terenkripsi agar tetap tampil di Meja Izin/Perangkat sampai dipakai
     * atau kedaluwarsa (keputusan pemilik 2026-10-29). Dihapus begitu laptop terpasang atau dilepas.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('pairing_token_encrypted')->nullable()->after('pairing_token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('pairing_token_encrypted');
        });
    }
};

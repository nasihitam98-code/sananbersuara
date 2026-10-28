<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No. urut pemilih per RT (seperti nomor di daftar absen RT). Pemilih yang sudah ada diberi nomor menurut urutan
     * dimasukkan (id), sehingga sama dengan urutan sheet yang ditempel.
     */
    public function up(): void
    {
        Schema::table('voters', function (Blueprint $table) {
            $table->unsignedInteger('rt_number')->nullable()->after('unit_id');
            $table->index(['unit_id', 'rt_number']);
        });

        DB::table('voters')->select('id', 'unit_id')->orderBy('unit_id')->orderBy('id')->get()
            ->groupBy('unit_id')
            ->each(function ($voters): void {
                foreach ($voters->values() as $index => $voter) {
                    DB::table('voters')->where('id', $voter->id)->update(['rt_number' => $index + 1]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voters', function (Blueprint $table) {
            $table->dropIndex(['unit_id', 'rt_number']);
            $table->dropColumn('rt_number');
        });
    }
};

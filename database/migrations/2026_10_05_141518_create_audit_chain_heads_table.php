<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris berisi hash terakhir rantai audit, dikunci saat menulis entri baru.
     */
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('last_hash', 64)->nullable();
            $table->unsignedBigInteger('last_audit_log_id')->nullable();
        });

        DB::table('audit_chain_heads')->insert(['id' => 1, 'last_hash' => null, 'last_audit_log_id' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
    }
};

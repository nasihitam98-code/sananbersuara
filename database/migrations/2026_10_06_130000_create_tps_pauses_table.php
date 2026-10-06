<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jeda per TPS RT (K15). Jeda aktif = resumed_at kosong.
     */
    public function up(): void
    {
        Schema::create('tps_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            $table->timestamp('paused_at');
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['election_id', 'unit_id', 'active_key'], 'tps_one_active_pause');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tps_pauses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gelombang buka-tutup di dalam satu putaran.
     */
    public function up(): void
    {
        Schema::create('waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('kind', 20);
            $table->string('status', 20)->default('DIBUKA');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('paused_remaining_seconds')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['round_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waves');
    }
};

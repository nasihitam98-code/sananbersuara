<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemilihan. access_code adalah kode acak di URL/QR pemilih (Mode Dadakan)
     * dan bisa diganti panitia jika QR bocor.
     */
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('mode', 20);
            $table->string('status', 20)->default('DRAFT')->index();
            $table->json('settings')->nullable();
            $table->string('access_code', 32)->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('results_revealed_at')->nullable();
            $table->timestamp('vote_links_destroyed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};

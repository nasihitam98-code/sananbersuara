<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Berita acara (bagian 9A). Isi disimpan sebagai snapshot JSON beserta checksum.
     * Setelah DISAHKAN isinya terkunci; perubahan = versi baru (versi lama DIGANTIKAN).
     */
    public function up(): void
    {
        Schema::create('official_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('number', 120);
            $table->string('status', 20);
            $table->longText('content');
            $table->char('checksum', 64);
            $table->text('revision_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ratified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ratified_at')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'unit_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('official_reports');
    }
};

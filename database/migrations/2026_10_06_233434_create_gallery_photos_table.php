<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Galeri foto calon: foto diunggah dulu, lalu dipilih untuk calon. File disimpan privat
     * (disk local), EXIF dibuang, dan hanya bisa dilihat Super Admin yang login.
     */
    public function up(): void
    {
        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('original_name', 190);
            $table->string('file_key', 40)->unique();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('gallery_photo_id')->nullable()->after('photo_key')->constrained('gallery_photos')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gallery_photo_id');
        });

        Schema::dropIfExists('gallery_photos');
    }
};

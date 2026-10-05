<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('must_change_password')->default(true)->after('is_active');
            $table->text('app_authentication_secret')->nullable()->after('must_change_password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->timestamp('last_login_at')->nullable()->after('app_authentication_recovery_codes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'must_change_password', 'app_authentication_secret', 'app_authentication_recovery_codes', 'last_login_at']);
        });
    }
};

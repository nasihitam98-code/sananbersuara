<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log append-only. UPDATE dan DELETE ditolak oleh trigger database.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->unsignedBigInteger('election_id')->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->text('note')->nullable();
            // Teks (bukan JSON) agar tersimpan persis seperti saat di-hash; tipe JSON MySQL menormalkan isi.
            $table->text('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['election_id', 'occurred_at']);
        });

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $this->createMysqlTriggers(),
            'sqlite' => $this->createSqliteTriggers(),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared("CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        DB::unprepared("CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared("CREATE TRIGGER audit_logs_block_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;");
        DB::unprepared("CREATE TRIGGER audit_logs_block_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;");
    }
};

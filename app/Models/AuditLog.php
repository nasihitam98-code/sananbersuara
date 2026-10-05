<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Entri audit append-only. Ditulis hanya lewat AuditLogger; perubahan dan penghapusan
 * ditolak di aplikasi dan di database (trigger).
 */
#[WithoutTimestamps]
class AuditLog extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit log tidak bisa diubah.'));
        static::deleting(fn (): never => throw new LogicException('Audit log tidak bisa dihapus.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}

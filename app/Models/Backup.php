<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan satu file backup. Dibuat dan diubah hanya oleh BackupService.
 */
#[RouteKey('public_id')]
class Backup extends Model
{
    use HasUlids;

    public const STATUS_RUNNING = 'BERJALAN';

    public const STATUS_SUCCESS = 'BERHASIL';

    public const STATUS_FAILED = 'GAGAL';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offsite_copied' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Backups\BackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduh file backup (tetap terenkripsi). Hanya lewat tautan bertanda tangan berumur pendek
 * yang dibuat setelah konfirmasi password, dan hanya untuk Super Admin yang membuatnya.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, Backup $backup, BackupService $backups): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isSuperAdmin() && (int) $request->query('user') === $user->id && $backup->status === Backup::STATUS_SUCCESS, 403);

        app(AuditLogger::class)->log('backup.downloaded', $backup, actor: $user);

        return response()->download($backups->absolutePath($backup), $backup->filename);
    }
}

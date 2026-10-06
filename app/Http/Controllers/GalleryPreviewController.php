<?php

namespace App\Http\Controllers;

use App\Models\GalleryPhoto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tampilkan foto Galeri Foto calon. Foto galeri bersifat privat: hanya Super Admin yang login.
 */
class GalleryPreviewController extends Controller
{
    public function __invoke(Request $request, GalleryPhoto $galleryPhoto, string $size = 'thumb'): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);

        $path = $galleryPhoto->path($size === 'full' ? 'full' : 'thumb');
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}

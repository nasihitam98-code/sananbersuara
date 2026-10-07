<?php

use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\BoothController;
use App\Http\Controllers\GalleryPreviewController;
use App\Http\Controllers\OfficialReportController;
use App\Http\Controllers\PublicResultController;
use App\Http\Controllers\RecapExportController;
use App\Http\Controllers\ScreenController;
use App\Http\Controllers\VoterController;
use App\Http\Controllers\VoterTemplateController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\NoStore;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Halaman publik: hanya hasil resmi yang sudah dipublikasikan
|--------------------------------------------------------------------------
*/

Route::get('/', [PublicResultController::class, 'index'])->name('public.index');
Route::get('/hasil/{election:public_id}', [PublicResultController::class, 'show'])->name('public.show');

/*
|--------------------------------------------------------------------------
| Halaman pemilih Mode Dadakan (satu QR untuk semua)
|--------------------------------------------------------------------------
*/

Route::prefix('v/{accessCode}')
    ->name('voter.')
    ->where(['accessCode' => '[a-z0-9]{8,32}', 'attendeeId' => '[0-9A-Za-z]{26}'])
    ->middleware([NoStore::class, 'throttle:voter-page'])
    ->controller(VoterController::class)
    ->group(function (): void {
        Route::get('/', 'show')->name('show');
        // Dipanggil ratusan HP tiap ~3 detik: tanpa sesi/cookie agar tidak menulis ke database.
        Route::get('/status', 'status')->name('status')
            ->withoutMiddleware(['throttle:voter-page', StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, EncryptCookies::class, PreventRequestForgery::class])
            ->middleware('throttle:voter-status');
        Route::get('/cari', 'search')->name('search')->middleware('throttle:voter-search');
        Route::get('/pin/{attendeeId}', 'pinForm')->name('pin');
        Route::post('/pin/{attendeeId}', 'verifyPin')->name('pin.verify')->middleware('throttle:voter-pin');
        Route::get('/surat-suara', 'ballot')->name('ballot');
        Route::post('/surat-suara', 'cast')->name('cast');
        Route::get('/selesai', 'done')->name('done');
        Route::post('/keluar', 'leave')->name('leave');
    });

/*
|--------------------------------------------------------------------------
| Mode Resmi: laptop Bilik dan pemasangan laptop Meja (sesi perangkat lewat cookie)
|--------------------------------------------------------------------------
*/

Route::middleware(NoStore::class)->controller(BoothController::class)->group(function (): void {
    Route::get('/bilik', 'show')->name('booth.show');
    Route::get('/bilik/status', 'status')->name('booth.status');
    Route::get('/bilik/surat-suara', 'ballot')->name('booth.ballot');
    Route::post('/bilik/sentuh', 'touch')->name('booth.touch');
    Route::post('/bilik/pilih', 'cast')->name('booth.cast');
    Route::get('/bilik/selesai', 'done')->name('booth.done');
    Route::get('/meja/pasang', 'pairForm')->name('desk.pair');
    Route::post('/{kind}/pasang', 'pair')->name('booth.pair')->whereIn('kind', ['bilik', 'meja'])->middleware('throttle:device-pair');
});

/*
|--------------------------------------------------------------------------
| Layar proyektor panitia (butuh login)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('layar')->name('screens.')->group(function (): void {
    Route::get('/qr/{election:public_id}', [ScreenController::class, 'qr'])->name('qr');
    Route::get('/qr/{election:public_id}/status', [ScreenController::class, 'qrStatus'])->name('qr.status');
});

Route::middleware('auth')->get('/berita-acara/{report:public_id}', [OfficialReportController::class, 'show'])->name('reports.show');
Route::middleware('auth')->get('/pemilih/template', VoterTemplateController::class)->name('voters.template');
Route::middleware('auth')->get('/rekap/{election:public_id}', RecapExportController::class)->name('recap.export');
Route::middleware(['auth', 'signed'])->get('/backup/{backup:public_id}/unduh', BackupDownloadController::class)->name('backups.download');
Route::middleware('auth')->get('/panel/galeri/{galleryPhoto:public_id}/{size?}', GalleryPreviewController::class)->whereIn('size', ['thumb', 'full'])->name('gallery.preview');
Route::middleware('auth')->get('/panel/mode/{mode?}', WorkspaceController::class)->whereIn('mode', ['dadakan', 'resmi', 'pilih'])->name('workspace.switch');

<?php

use App\Http\Controllers\OfficialReportController;
use App\Http\Controllers\PublicResultController;
use App\Http\Controllers\ScreenController;
use App\Http\Controllers\VoterController;
use App\Http\Controllers\VoterTemplateController;
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
| Layar proyektor panitia (butuh login)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('layar')->name('screens.')->group(function (): void {
    Route::get('/qr/{election:public_id}', [ScreenController::class, 'qr'])->name('qr');
});

Route::middleware('auth')->get('/berita-acara/{report:public_id}', [OfficialReportController::class, 'show'])->name('reports.show');
Route::middleware('auth')->get('/pemilih/template', VoterTemplateController::class)->name('voters.template');

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();

        Password::defaults(fn (): Password => Password::min(12)
            ->letters()
            ->numbers()
            ->when($this->app->isProduction(), fn (Password $rule): Password => $rule->uncompromised()));
    }

    /**
     * Batas pemilih dihitung per sesi (per HP), bukan hanya per IP: ratusan HP di lokasi rapat
     * bisa keluar lewat satu IP yang sama (WiFi/NAT operator). Batas per IP dibuat longgar
     * sebagai rem terakhir; perlindungan PIN utama adalah kunci setelah 3 kali salah.
     */
    private function configureRateLimits(): void
    {
        $perDevice = fn (Request $request): string => $request->hasSession() ? $request->session()->getId() : (string) $request->ip();

        RateLimiter::for('voter-page', fn (Request $request): array => [
            Limit::perMinute(60)->by('page:'.$perDevice($request)),
            Limit::perMinute(6000)->by('page-ip:'.$request->ip()),
        ]);

        // Endpoint status tanpa sesi, jadi hanya bisa dibatasi per IP (longgar, karena NAT bersama).
        RateLimiter::for('voter-status', fn (Request $request): Limit => Limit::perMinute(20000)->by('status-ip:'.$request->ip()));

        RateLimiter::for('voter-search', fn (Request $request): array => [
            Limit::perMinute(20)->by('search:'.$perDevice($request)),
            Limit::perMinute(3000)->by('search-ip:'.$request->ip()),
        ]);

        RateLimiter::for('voter-pin', fn (Request $request): array => [
            Limit::perMinute(8)->by('pin:'.$perDevice($request)),
            Limit::perMinute(1500)->by('pin-ip:'.$request->ip()),
        ]);
    }
}

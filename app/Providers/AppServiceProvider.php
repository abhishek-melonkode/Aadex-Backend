<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Models live under app/Domain/<Context>/Models, not app/Models, so
        // Laravel's default guess (Database\Factories\Domain\...\XFactory)
        // never resolves. Every factory lives flat in database/factories and
        // is named after the model's class basename.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->registerRateLimiters();
    }

    /**
     * Laravel's `api` middleware group ships with NO throttling — it is opt-in
     * via `throttleApi()` in bootstrap/app.php, which needs the `api` limiter
     * below to exist. Without these, login and the 6-digit password-reset OTP
     * are both brute-forceable at whatever rate the network allows.
     *
     * Each auth limiter is keyed by email+IP first (stops a targeted attack on
     * one account) and by IP second (stops spraying across many accounts).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($this->emailKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Tighter than the others: every allowed request sends a real email.
        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perMinute(3)->by($this->emailKey($request)),
            Limit::perMinute(10)->by($request->ip()),
        ]);

        RateLimiter::for('reset-password', fn (Request $request) => [
            Limit::perMinute(6)->by($this->emailKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }

    private function emailKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip();
    }
}

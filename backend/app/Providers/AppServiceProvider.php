<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        // Every catalogue permission becomes a Gate ability (e.g. Gate::allows('finance.refund')).
        foreach (PermissionCatalog::allSlugs() as $slug) {
            Gate::define($slug, fn (User $user) => $user->is_active && $user->hasPermission($slug));
        }

        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));

        // Login: 5 attempts/minute per email+IP, plus 20/minute per IP.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        RateLimiter::for('contact', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('booking-quote', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('booking-create', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}

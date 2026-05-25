<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Register middleware aliases
        $this->app['router']->aliasMiddleware('auth', \Illuminate\Auth\Middleware\Authenticate::class);
        $this->app['router']->aliasMiddleware('auth.basic', \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class);
        $this->app['router']->aliasMiddleware('auth.session', \Illuminate\Session\Middleware\AuthenticateSession::class);
        $this->app['router']->aliasMiddleware('auth:sanctum', \Illuminate\Auth\Middleware\Authenticate::class);
        $this->app['router']->aliasMiddleware('cache.headers', \Illuminate\Http\Middleware\SetCacheHeaders::class);
        $this->app['router']->aliasMiddleware('can', \Illuminate\Auth\Middleware\Authorize::class);
        $this->app['router']->aliasMiddleware('guest', \App\Http\Middleware\RedirectIfAuthenticated::class);
        $this->app['router']->aliasMiddleware('password.confirm', \Illuminate\Auth\Middleware\RequirePassword::class);
        $this->app['router']->aliasMiddleware('precognitive', \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class);
        $this->app['router']->aliasMiddleware('signed', \App\Http\Middleware\ValidateSignature::class);
        $this->app['router']->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->app['router']->aliasMiddleware('verified', \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class);
        $this->app['router']->aliasMiddleware('role', \App\Http\Middleware\CheckRole::class);
        $this->app['router']->aliasMiddleware('permission', \App\Http\Middleware\CheckPermission::class);

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/auth.php'));
        });
    }
}
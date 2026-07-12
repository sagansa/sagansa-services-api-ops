<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('testing')) {
            $db = $this->app->make('db');
            foreach (['mysql', 'mysql_auth', 'mysql_ops'] as $connection) {
                $db->extend($connection, function ($config, $name) use ($db) {
                    return $db->connection('sqlite');
                });
            }
        }

        $this->app->bind(
            \App\Contracts\ImageStorageContract::class,
            \App\Services\ImageUploadService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Super-admin bypass: Grant all permissions automatically
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        // Register observers
        \App\Models\Store::observe(\App\Observers\StoreObserver::class);

        if ($this->app->environment('testing')) {
            $basePath = base_path('../migration/database/migrations');
            $this->loadMigrationsFrom([
                $basePath,
                $basePath . '/auth',
                $basePath . '/ops',
            ]);
        }
    }
}

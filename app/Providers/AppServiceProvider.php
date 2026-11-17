<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.key'),
            timeout: config('services.pixelforge.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->is_admin);
    }
}

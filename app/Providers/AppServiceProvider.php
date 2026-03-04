<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        if (app()->environment(['production', 'testing'])) {
            URL::forceScheme('https');
        }

        // Configura o Sanctum para autenticar via Bearer token
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid) {
            // Verifica se o token ainda não expirou
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return false;
            }

            return $isValid;
        });
    }
}

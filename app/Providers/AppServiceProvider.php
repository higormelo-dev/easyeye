<?php

namespace App\Providers;

use App\Services\FeatureGateService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton para que o cache por request funcione corretamente
        $this->app->singleton(FeatureGateService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment(['production', 'testing'])) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

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

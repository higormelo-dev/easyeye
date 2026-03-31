<?php

namespace App\Providers;

use App\Models\{Doctor, Entity, EntityIntegrator, EntityUser, MedicalRecord, Patient, Schedule, Subscription};
use App\Observers\{ActivationObserver, EntityObserver, SubscriptionObserver};
use App\Services\{ActivationService, AuditService, FeatureGateService, PartnerService, ReferralService, VersionService};
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
        // Singletons: cache por request
        $this->app->singleton(FeatureGateService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(VersionService::class);

        // CAC: singletons dos serviços de aquisição
        $this->app->singleton(ActivationService::class);
        $this->app->singleton(ReferralService::class);
        $this->app->singleton(PartnerService::class);
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
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return false;
            }

            return $isValid;
        });

        // -------------------------------------------------------------------------
        // Observers existentes
        // -------------------------------------------------------------------------
        Entity::observe(EntityObserver::class);

        // -------------------------------------------------------------------------
        // CAC: Activation Tracking — observa múltiplos models via métodos nomeados
        // -------------------------------------------------------------------------
        $activationObserver = $this->app->make(ActivationObserver::class);

        Doctor::created(fn ($m)           => $activationObserver->created($m));
        Patient::created(fn ($m)          => $activationObserver->patientCreated($m));
        Schedule::created(fn ($m)         => $activationObserver->scheduleCreated($m));
        MedicalRecord::created(fn ($m)    => $activationObserver->medicalRecordCreated($m));
        EntityUser::created(fn ($m)       => $activationObserver->entityUserCreated($m));
        EntityIntegrator::created(fn ($m) => $activationObserver->entityIntegratorCreated($m));
        Entity::updated(fn ($m)           => $activationObserver->entityUpdated($m));

        // -------------------------------------------------------------------------
        // CAC: Subscription events — comissão de parceiro + reward de indicação
        // -------------------------------------------------------------------------
        Subscription::observe(SubscriptionObserver::class);
    }
}

<?php

namespace App\Providers;

use App\Enums\FeatureKey;
use App\Models\Entity;
use App\Observers\EntityObserver;
use App\Services\{FeatureGateService, SubscriptionService, TrialService, UsageMeterService};
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton para evitar múltiplas instâncias por request
        $this->app->singleton(UsageMeterService::class);
        $this->app->singleton(FeatureGateService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(TrialService::class);
    }

    public function boot(): void
    {
        // Observer para auto-iniciar trial na criação de empresa
        Entity::observe(EntityObserver::class);

        // Gates por feature — uso: Gate::allows('feature', FeatureKey::MaxPatients)
        Gate::define('feature', function ($user, FeatureKey $feature) {
            $entityId = session('selected_entity_id');

            if (! $entityId) {
                return false;
            }

            return app(FeatureGateService::class)->can($entityId, $feature);
        });

        // Gate de acesso geral à assinatura
        Gate::define('subscription.active', function ($user) {
            $entityId = session('selected_entity_id');

            if (! $entityId) {
                return false;
            }

            $entity = Entity::find($entityId);

            return $entity && (! $entity->is_client || app(SubscriptionService::class)->hasAccess($entity));
        });
    }
}

<?php

namespace App\Providers;

use App\Domains\AI\Contracts\{AiCircuitBreakerInterface, AiModelPriceRepositoryInterface, AiRunProviderCallStoreInterface, AiRunRepositoryInterface};
use App\Domains\AI\Providers\{AnthropicProvider, GeminiProvider, OpenAiProvider};
use App\Domains\AI\Providers\Fakes\{AnthropicFakeProvider, GeminiFakeProvider, OpenAiFakeProvider};
use App\Domains\AI\Repositories\{EloquentAiModelPriceRepository, EloquentAiRunProviderCallStore, EloquentAiRunRepository};
use App\Domains\AI\Services\{AiCircuitBreakerService, AiProviderManager, AiProviderSettings};
use App\Models\{Doctor, Entity, EntityIntegrator, EntityUser, MedicalRecord, Patient, Schedule, Subscription};
use App\Observers\{ActivationObserver, SubscriptionObserver};
use App\Services\{ActivationService, AuditService, FeatureGateService, PartnerService, ReferralService, VersionService};
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\{Blade, Gate, RateLimiter, URL};
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\{PersonalAccessToken, Sanctum};

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singletons: cache por request
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(FeatureGateService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(VersionService::class);
        $this->app->bind(AiModelPriceRepositoryInterface::class, EloquentAiModelPriceRepository::class);
        $this->app->bind(AiRunProviderCallStoreInterface::class, EloquentAiRunProviderCallStore::class);
        $this->app->bind(AiRunRepositoryInterface::class, EloquentAiRunRepository::class);

        // Circuit breaker dos providers LLM. Threshold/cooldown configuráveis via env.
        $this->app->singleton(AiCircuitBreakerInterface::class, function (): AiCircuitBreakerService {
            return new AiCircuitBreakerService(
                threshold: (int) config('ai.circuit_breaker.threshold', 5),
                cooldownSeconds: (int) config('ai.circuit_breaker.cooldown_seconds', 120),
            );
        });

        $this->app->singleton(AiProviderManager::class, function ($app): AiProviderManager {
            $runtime  = (string) config('ai.provider_runtime', 'fake');
            $settings = $app->make(AiProviderSettings::class);

            if ($runtime === 'real') {
                return new AiProviderManager([
                    'openai'    => $app->make(OpenAiProvider::class),
                    'anthropic' => $app->make(AnthropicProvider::class),
                    'gemini'    => $app->make(GeminiProvider::class),
                ], $settings);
            }

            return new AiProviderManager([
                'openai'    => $app->make(OpenAiFakeProvider::class),
                'anthropic' => $app->make(AnthropicFakeProvider::class),
                'gemini'    => $app->make(GeminiFakeProvider::class),
            ], $settings);
        });

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
            URL::forceRootUrl(config('app.url'));
        }

        Paginator::useBootstrapFive();

        // Carbon::isoFormat usa o locale do Carbon (independente de app()->getLocale()).
        // SetLocale middleware atualiza per-request; este boot cobre CLI/jobs/PDFs gerados
        // fora do contexto web (ex: queue worker enviando relatório por e-mail).
        Carbon::setLocale(config('app.locale', 'pt_BR'));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Configura o Sanctum para autenticar via Bearer token
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid) {
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return false;
            }

            return $isValid;
        });

        // -------------------------------------------------------------------------
        // Blade: @canEntity('entity.manage-users') / @endcanEntity
        //
        // Alternativa limpa ao session('selected_entity_user_rule') === 'admin'
        // nas views. Delega para os Gates definidos em AuthServiceProvider,
        // resolvendo a entity a partir da sessão ativa do painel.
        // -------------------------------------------------------------------------
        Blade::if('canEntity', function (string $gate): bool {
            $entityId = session('selected_entity_id');

            if (! $entityId) {
                return false;
            }

            $entity = Entity::find($entityId);

            if (! $entity) {
                return false;
            }

            return Gate::allows($gate, $entity);
        });

        // -------------------------------------------------------------------------
        // CAC: Activation Tracking — observa múltiplos models via métodos nomeados
        // -------------------------------------------------------------------------
        $activationObserver = $this->app->make(ActivationObserver::class);

        Doctor::created(fn ($m) => $activationObserver->created($m));
        Patient::created(fn ($m) => $activationObserver->patientCreated($m));
        Schedule::created(fn ($m) => $activationObserver->scheduleCreated($m));
        MedicalRecord::created(fn ($m) => $activationObserver->medicalRecordCreated($m));
        EntityUser::created(fn ($m) => $activationObserver->entityUserCreated($m));
        EntityIntegrator::created(fn ($m) => $activationObserver->entityIntegratorCreated($m));
        Entity::updated(fn ($m) => $activationObserver->entityUpdated($m));

        // -------------------------------------------------------------------------
        // CAC: Subscription events — comissão de parceiro + reward de indicação
        // -------------------------------------------------------------------------
        Subscription::observe(SubscriptionObserver::class);

        // -------------------------------------------------------------------------
        // Rate limits das rotas AI (Fase 7).
        // Chave por (user_id|entity_id) — entity_id vem da sessão do painel.
        // Quando não há user, cai para o IP (caso degenerado, raramente atingido
        // porque as rotas são protegidas por auth+entity.selected).
        // -------------------------------------------------------------------------
        $aiKey = function (Request $request): string {
            $userId   = $request->user()?->id ?? $request->ip();
            $entityId = $request->session()->get('selected_entity_id', 'global');

            return "ai:{$userId}:{$entityId}";
        };

        RateLimiter::for(
            'ai-estimate',
            fn (Request $r) => Limit::perMinute((int) config('ai.rate_limits.estimate_per_minute', 60))->by($aiKey($r)),
        );
        RateLimiter::for(
            'ai-store',
            fn (Request $r) => Limit::perMinute((int) config('ai.rate_limits.store_per_minute', 10))->by($aiKey($r)),
        );
        RateLimiter::for(
            'ai-decision',
            fn (Request $r) => Limit::perMinute((int) config('ai.rate_limits.decision_per_minute', 30))->by($aiKey($r)),
        );

        // -------------------------------------------------------------------------
        // Hardening Manager SaaS: rate limiters granulares.
        //
        // Chave por (user_id) — não por IP, porque admins legítimos podem operar
        // de IPs corporativos compartilhados. user_id também evita que um admin
        // saturado afete outro.
        //
        // - manager-read     : 60/min  - cards/listagens (já existe throttle:30,1
        //                                no grupo de rotas; este é teto adicional)
        // - manager-write    : 30/min  - update/store padrão
        // - manager-destructive : 5/min - cancel/block/destroy/revoke/impersonate
        //                                Ações de alto impacto exigem cadência humana,
        //                                não automação. Conta comprometida fica
        //                                limitada a 5 estragos/min.
        // -------------------------------------------------------------------------
        $managerKey = static fn (Request $r): string => 'manager:' . ($r->user()?->id ?? $r->ip());

        RateLimiter::for(
            'manager-read',
            static fn (Request $r) => Limit::perMinute(60)->by($managerKey($r)),
        );
        RateLimiter::for(
            'manager-write',
            static fn (Request $r) => Limit::perMinute(30)->by($managerKey($r)),
        );
        RateLimiter::for(
            'manager-destructive',
            static fn (Request $r) => Limit::perMinute(5)->by($managerKey($r)),
        );

        // -------------------------------------------------------------------------
        // API de integradores (cliente desktop Rust): teto por INTEGRADOR, não por
        // IP — várias clínicas podem sair pelo mesmo IP corporativo/NAT, e um token
        // vazado deve ser contido sozinho sem afetar os demais.
        //
        // Buckets separados read/write para que uma sincronização pesada de leitura
        // (lista de pacientes/agenda) não consuma a cota de upload de exames e
        // vice-versa. O método HTTP decide o bucket (POST/PATCH/DELETE = write).
        //
        // Fallback para IP só ocorre antes de auth_with_integrator definir o
        // atributo — na prática o throttle roda dentro do grupo v1, já autenticado.
        // -------------------------------------------------------------------------
        $integratorKey = static fn (Request $r): string => (string) (
            $r->attributes->get('integrator')?->id ?? $r->ip()
        );

        RateLimiter::for('integrators-api', static function (Request $r) use ($integratorKey) {
            $id = $integratorKey($r);

            return $r->isMethodSafe()
                ? Limit::perMinute(120)->by("int-read:{$id}")
                : Limit::perMinute(40)->by("int-write:{$id}");
        });
    }
}

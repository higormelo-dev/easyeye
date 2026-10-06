<?php

namespace App\Providers;

use App\Domains\AI\Contracts\{AiCircuitBreakerInterface, AiModelPriceRepositoryInterface, AiRunProviderCallStoreInterface, AiRunRepositoryInterface};
use App\Domains\AI\Providers\{AnthropicProvider, GeminiProvider, OpenAiCompatibleProvider, OpenAiProvider};
use App\Domains\AI\Providers\Fakes\{AnthropicFakeProvider, CompatibleFakeProvider, GeminiFakeProvider, OpenAiFakeProvider};
use App\Domains\AI\Repositories\{EloquentAiModelPriceRepository, EloquentAiRunProviderCallStore, EloquentAiRunRepository};
use App\Domains\AI\Services\{AiCircuitBreakerService, AiProviderManager, AiProviderSettings};
use App\Enums\AI\AiProvider;
use App\Models\{Doctor, Entity, EntityIntegrator, EntityUser, MedicalRecord, Patient, Schedule, Subscription};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Observers\{ActivationObserver, SubscriptionObserver};
use App\Services\{ActivationService, AuditService, FeatureGateService, PartnerService, ReferralService, VersionService};
use App\Services\Billing\{GatewayRegistry, WebhookIngestionService};
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\{GupshupProvider, MockWhatsAppProvider};
use App\Support\Database\AccentInsensitiveSearch;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\{Auth, Blade, Gate, RateLimiter, URL};
use Illuminate\Support\{ServiceProvider, Str};
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

        // WhatsApp oficial: gupshup envia de verdade; mock (padrão, também
        // com WHATSAPP_DRIVER vazio) só simula — mesmo padrão do TISS.
        $this->app->bind(WhatsAppProvider::class, fn ($app): WhatsAppProvider => config('whatsapp.driver') === 'gupshup'
            ? $app->make(GupshupProvider::class)
            : $app->make(MockWhatsAppProvider::class));

        // Circuit breaker dos providers LLM. Threshold/cooldown configuráveis via env.
        $this->app->singleton(AiCircuitBreakerInterface::class, function (): AiCircuitBreakerService {
            return new AiCircuitBreakerService(
                threshold: (int) config('ai.circuit_breaker.threshold', 5),
                cooldownSeconds: (int) config('ai.circuit_breaker.cooldown_seconds', 120),
            );
        });

        $this->app->singleton(AiProviderManager::class, function ($app): AiProviderManager {
            $real      = (string) config('ai.provider_runtime', 'fake') === 'real';
            $providers = $real
                ? [
                    'openai'    => $app->make(OpenAiProvider::class),
                    'anthropic' => $app->make(AnthropicProvider::class),
                    'gemini'    => $app->make(GeminiProvider::class),
                ]
                : [
                    'openai'    => $app->make(OpenAiFakeProvider::class),
                    'anthropic' => $app->make(AnthropicFakeProvider::class),
                    'gemini'    => $app->make(GeminiFakeProvider::class),
                ];

            // Lista pronta "compatível com OpenAI" (Mistral, Groq, xAI, Azure
            // OpenAI, Maritaca): um driver genérico por provedor.
            foreach (AiProvider::cases() as $provider) {
                if ($provider->isOpenAiCompatible()) {
                    $providers[$provider->value] = $real
                        ? new OpenAiCompatibleProvider($provider)
                        : new CompatibleFakeProvider($provider);
                }
            }

            return new AiProviderManager($providers, $app->make(AiProviderSettings::class));
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
        // BUGFIX: forçar HTTPS por NOME de ambiente (production/testing) quebra
        // qualquer setup local que rode APP_ENV=testing sem TLS (ex.: docker
        // nginx local só com `listen 80`, sem certificado) — toda URL absoluta
        // gerada (redirect()->route(), etc) vira https:// e o browser tenta
        // handshake TLS num servidor que só fala HTTP puro (ERR_CONNECTION_
        // CLOSED). A fonte de verdade correta é o scheme que APP_URL já
        // declara, não o nome do ambiente.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));

            // Links de paginação seguem o mesmo APP_URL que route()/redirect()
            // já usam. O resolver padrão lê o host da requisição, que depende do
            // protocolo/proxy: sob HTTP/3 o Nginx não repassa o Host ao PHP-FPM
            // e os links viravam https://_/... (server_name catch-all).
            Paginator::currentPathResolver(fn () => url()->current());
        }

        Paginator::useBootstrapFive();

        // Macro whereLikeUnaccent/orWhereLikeUnaccent — busca insensível a
        // acento (requer extensão unaccent do Postgres). Ver
        // App\Support\Database\AccentInsensitiveSearch.
        AccentInsensitiveSearch::register();

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
        // - manager-read     : 60/min  - cards/listagens (aplicado no grupo de
        //                                rotas routes/manager.php; era um literal
        //                                'throttle:30,1' — trocado porque 30/min
        //                                compartilhado entre TODAS as leituras do
        //                                painel manager estourava com poucos
        //                                cliques de paginação)
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
        // Checkout transparente da assinatura: pagamento (cartão, contratação,
        // troca de cartão) com teto baixo — teste de cartões roubados (card
        // testing) é o abuso típico; leitura (instruções Pix/boleto, opções)
        // mais folgada. Chave por usuário + clínica (e IP sem login).
        // -------------------------------------------------------------------------
        $checkoutKey = static fn (Request $r): string => 'checkout:' . ($r->user()?->id ?? $r->ip()) . ':' . $r->session()->get('selected_entity_id', 'none');

        RateLimiter::for(
            'billing-checkout-pay',
            static fn (Request $r) => [
                Limit::perMinute(5)->by($checkoutKey($r)),
                Limit::perHour(20)->by($checkoutKey($r)),
            ],
        );
        RateLimiter::for(
            'billing-checkout-read',
            static fn (Request $r) => Limit::perMinute(30)->by($checkoutKey($r)),
        );

        // Cadastro no site (POST /register): cada cadastro é uma conta nova com
        // o próprio limite de pagamento — o teto por IP impede abrir contas em
        // série para testar cartões (card testing) pelo checkout do cadastro.
        $signupTooMany = static fn () => response()->json(['message' => __('auth.register.too_many_signups')], 429);

        RateLimiter::for(
            'register',
            static fn (Request $r) => [
                Limit::perHour(max(1, (int) config('billing.checkout.fraud.register_per_ip_hour', 10)))->by('register:hour:' . $r->ip())->response($signupTooMany),
                Limit::perDay(max(1, (int) config('billing.checkout.fraud.register_per_ip_day', 30)))->by('register:day:' . $r->ip())->response($signupTooMany),
            ],
        );

        // -------------------------------------------------------------------------
        // Painel financeiro da clínica: mutações (faturar, marcar paga/negada,
        // fechar caixa, importar retorno TISS) e as exportações pesadas
        // (XML/CSV com drill-down) não tinham nenhum teto — sessão comprometida
        // conseguia automatizar parsing de XML/geração de relatório sem barreira.
        // -------------------------------------------------------------------------
        // Webhook do WhatsApp (Gupshup): limite por token da URL — cada
        // configuração (app) tem o seu. O IP não serve: com trustProxies '*'
        // ele vem do X-Forwarded-For, que qualquer um forja. Token que não
        // existe cai num balde GLOBAL (chave fixa whatsapp-webhook:unknown) —
        // senão cada token aleatório ganharia o seu e o limite nunca
        // estouraria. Mesma resposta 404 genérica até estourar (429).
        // Webhook de billing: limite por gateway (código da URL), alto — os
        // eventos chegam em rajada (CONFIRMED + RECEIVED, renovações do mês) e
        // 429 conta como falha de entrega no Asaas (fila penalizada/pausada).
        // Só a requisição com o token/assinatura válidos do gateway conta no
        // balde dele: sem token ou com token falso, balde próprio e pequeno
        // por IP — um flood falso nunca gera 429 para o gateway de verdade.
        // Gateway que não existe cai num balde único pequeno.
        RateLimiter::for('billing-webhook', static function (Request $r) {
            $gateway = strtolower((string) $r->route('gateway'));

            if (! app(GatewayRegistry::class)->has($gateway)) {
                return Limit::perMinute(60)->by('billing-webhook:unknown');
            }

            if (! app(WebhookIngestionService::class)->authenticates($gateway, $r->headers->all(), (string) $r->getContent())) {
                return Limit::perMinute(max(1, (int) config('billing.webhooks.invalid_rate_limit_per_minute', 30)))
                    ->by('billing-webhook:invalid:' . $gateway . ':' . $r->ip());
            }

            return Limit::perMinute(max(60, (int) config('billing.webhooks.rate_limit_per_minute', 3000)))
                ->by('billing-webhook:' . $gateway);
        });

        RateLimiter::for('whatsapp-webhook', static function (Request $r) {
            $token = (string) $r->route('token');

            if ($token === '' || ! WhatsAppSetting::query()->where('webhook_token', $token)->exists()) {
                return Limit::perMinute(max(1, (int) config('whatsapp.webhook.unknown_rate_limit_per_minute', 60)))
                    ->by('whatsapp-webhook:unknown');
            }

            return Limit::perMinute(max(1, (int) config('whatsapp.webhook.rate_limit_per_minute', 1200)))
                ->by('whatsapp-webhook:' . sha1($token));
        });

        RateLimiter::for(
            'financial-write',
            static fn (Request $r) => Limit::perMinute(30)->by(
                'financial:' . ($r->user()?->id ?? $r->ip()),
            ),
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

        // -------------------------------------------------------------------------
        // Portal do Paciente (Fase 1): login rate-limited por e-mail normalizado
        // + IP, NÃO só por IP cru — um IP compartilhado (NAT, wifi de clínica)
        // não deve travar todos os pacientes que tentam logar por trás dele, e
        // um atacante trocando de IP não deve conseguir bypassar o limite por
        // e-mail só variando o IP (a chave combina os dois).
        // -------------------------------------------------------------------------
        RateLimiter::for('patient-login', static function (Request $r) {
            $key = Str::lower((string) $r->input('email')) . '|' . $r->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Evita enumeração de e-mail (quais contas existem em patient_accounts)
        // e "email bombing" de link de reset — mesma chave email+IP do login.
        RateLimiter::for('patient-password-email', static function (Request $r) {
            $key = Str::lower((string) $r->input('email')) . '|' . $r->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Aceite de convite: o POST também confere a senha da conta logada
        // (vincular clínica) — limite por CONTA do portal (não só por IP, que
        // o guard "web" usaria por padrão); convidado sem sessão: por IP.
        // Convite a médico com login existente: por CLÍNICA (evita varredura de
        // quem tem login e e-mails em massa a partir de uma clínica).
        RateLimiter::for('doctor-invitations', static function (Request $r) {
            return [
                Limit::perHour(20)->by('doctor-invitations|entity|' . (string) $r->session()->get('selected_entity_id', $r->ip())),
                Limit::perHour(20)->by('doctor-invitations|user|' . ($r->user()?->id ?? $r->ip())),
            ];
        });

        // Convite a usuário: a resposta não revela nada, mas cada convite pode
        // gerar e-mail — limite por clínica e por usuário.
        RateLimiter::for('user-invitations', static function (Request $r) {
            return [
                Limit::perHour(30)->by('user-invitations|entity|' . (string) $r->session()->get('selected_entity_id', $r->ip())),
                Limit::perHour(30)->by('user-invitations|user|' . ($r->user()?->id ?? $r->ip())),
            ];
        });

        // Cadastro de médico: a validação revela se o e-mail/CPF já tem login
        // de médico no EasyEye (para oferecer o convite) — limite por usuário
        // e por clínica contra varredura. Folgado para o uso normal.
        RateLimiter::for('doctor-registrations', static function (Request $r) {
            return [
                Limit::perMinute(20)->by('doctor-registrations|user|' . ($r->user()?->id ?? $r->ip())),
                Limit::perHour(200)->by('doctor-registrations|entity|' . (string) $r->session()->get('selected_entity_id', $r->ip())),
            ];
        });

        RateLimiter::for('patient-invitation', static function (Request $r) {
            $key = 'patient-invitation|' . (Auth::guard('patient')->id() ?? $r->ip());

            return Limit::perMinute(6)->by($key);
        });
    }
}

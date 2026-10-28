<?php

namespace App\Providers;

use App\Domains\AI\Contracts\{AiCircuitBreakerInterface, AiModelPriceRepositoryInterface, AiRunProviderCallStoreInterface, AiRunRepositoryInterface};
use App\Domains\AI\Providers\{AnthropicProvider, GeminiProvider, OpenAiCompatibleProvider, OpenAiProvider};
use App\Domains\AI\Providers\Fakes\{AnthropicFakeProvider, CompatibleFakeProvider, GeminiFakeProvider, OpenAiFakeProvider};
use App\Domains\AI\Repositories\{EloquentAiModelPriceRepository, EloquentAiRunProviderCallStore, EloquentAiRunRepository};
use App\Domains\AI\Services\{AiCircuitBreakerService, AiProviderManager, AiProviderSettings};
use App\Enums\AI\AiProvider;
use App\Models\{Doctor, DoctorWorkSchedule, Entity, EntityIntegrator, EntityUser, MedicalRecord, Patient, Schedule, ScheduleBlock, Subscription};
use App\Models\Fiscal\NfseCredential;
use App\Models\WhatsApp\WhatsAppSetting;
use App\Observers\{ActivationObserver, SubscriptionObserver};
use App\Services\{ActivationService, AuditService, FeatureGateService, PartnerService, ReferralService, VersionService};
use App\Services\Billing\{GatewayRegistry, WebhookIngestionService};
use App\Services\Schedules\ScheduleAvailabilityService;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\{GupshupAuth, GupshupProvider, GupshupSelfServeProvider, MockWhatsAppProvider};
use App\Support\Database\AccentInsensitiveSearch;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\{Connection, LazyLoadingViolationException};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\{Auth, Blade, Context, DB, Gate, Log, Queue, RateLimiter, URL};
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
        $this->app->bind(WhatsAppProvider::class, fn ($app): WhatsAppProvider => match (true) {
            config('whatsapp.driver') !== 'gupshup'                              => $app->make(MockWhatsAppProvider::class),
            $app->make(GupshupAuth::class)->mode() === GupshupAuth::MODE_API_KEY => $app->make(GupshupSelfServeProvider::class),
            default                                                              => $app->make(GupshupProvider::class),
        });

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
        $this->bootObservability();

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
        // Agenda: vagas do mês em cache (ScheduleAvailabilityService) — qualquer
        // mudança de agendamento, escala, bloqueio ou intervalo troca a versão
        // do cache da clínica (todos os caminhos: tela, lote, importação, API).
        // -------------------------------------------------------------------------
        $bumpAvailability = fn (?string $entityId) => ScheduleAvailabilityService::bumpVersion($entityId);
        $doctorEntity     = fn (?string $doctorId) => $doctorId === null ? null : DB::table('doctors')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('doctors.id', $doctorId)
            ->value('entity_users.entity_id');

        Schedule::saved(fn (Schedule $s) => $bumpAvailability($s->entity_id));
        Schedule::deleted(fn (Schedule $s) => $bumpAvailability($s->entity_id));
        DoctorWorkSchedule::saved(fn (DoctorWorkSchedule $w) => $bumpAvailability($doctorEntity($w->doctor_id)));
        DoctorWorkSchedule::deleted(fn (DoctorWorkSchedule $w) => $bumpAvailability($doctorEntity($w->doctor_id)));
        ScheduleBlock::saved(fn (ScheduleBlock $b) => $bumpAvailability($doctorEntity($b->doctor_id)));
        ScheduleBlock::deleted(fn (ScheduleBlock $b) => $bumpAvailability($doctorEntity($b->doctor_id)));
        Doctor::saved(fn (Doctor $d) => $bumpAvailability($doctorEntity((string) $d->id)));
        Doctor::deleted(fn (Doctor $d) => $bumpAvailability($doctorEntity((string) $d->id)));
        Entity::saved(function (Entity $e) use ($bumpAvailability): void {
            if ($e->wasChanged(['schedule_interval', 'few_slots_percent'])) {
                $bumpAvailability((string) $e->id);
            }
        });

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
        // Ditado do laudo (Fase 5): cada chamada gasta minutos de transcrição no provedor.
        RateLimiter::for(
            'report-dictation',
            fn (Request $r) => Limit::perMinute((int) config('ai.transcription.per_minute', 10))->by($aiKey($r)),
        );
        RateLimiter::for(
            'ai-decision',
            fn (Request $r) => Limit::perMinute((int) config('ai.rate_limits.decision_per_minute', 30))->by($aiKey($r)),
        );

        // Panorama e Auto-alinhar do Gerenciador de exames: cada chamada sobe um
        // processo Python/OpenCV (até 1,5 GB, vários núcleos). Os dois endpoints
        // dividem o mesmo balde por usuário+clínica; o teto global de processos
        // simultâneos fica no BoundedVisionProcess.
        RateLimiter::for(
            'eye-images-vision',
            fn (Request $r) => Limit::perMinute(6)->by(
                'vision:' . ($r->user()?->id ?? $r->ip()) . ':' . $r->session()->get('selected_entity_id', 'global'),
            ),
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

        // GET /register/check-email devolve se o e-mail já tem conta (o passo do wizard precisa):
        // sem limite, qualquer um enumeraria contas. Por IP, por minuto.
        // ATENÇÃO: "IP" aqui é Request::ip(), e bootstrap/app.php confia em TODOS os proxies — então o limite
        // segue o X-Forwarded-For e é contornável por quem rotaciona o cabeçalho (vale para todo throttle do app,
        // login/OTP inclusive). Restringir trustProxies ao nginx/CDN (TRUSTED_PROXIES) é DECISÃO DO USUÁRIO/infra,
        // pendente; o comportamento atual está documentado em RegistrationPageTest ([S2-b]).
        RateLimiter::for(
            'register-check-email',
            static fn (Request $r) => Limit::perMinute(max(1, (int) config('billing.checkout.fraud.check_email_per_ip_minute', 20)))
                ->by('register:check-email:' . $r->ip())
                ->response($signupTooMany),
        );

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

        // Webhook da Focus NFe (NFS-e do SaaS): balde por TOKEN da URL. Só a requisição com token E segredo certos conta no
        // balde da credencial (a Focus retenta tudo que não for 2xx — um flood falso nunca pode gerar 429 para ela); token
        // certo com segredo errado cai num balde pequeno por token; token desconhecido, num balde global fixo (o IP atrás
        // do proxy é forjável, então não serve de chave).
        RateLimiter::for('fiscal-webhook', static function (Request $r) {
            $token = (string) $r->route('token');
            $match = null;

            foreach (NfseCredential::query()->get() as $credential) {
                if (hash_equals((string) $credential->webhook_token, $token)) {
                    $match = $credential;
                }
            }

            if ($match === null) {
                return Limit::perMinute(max(1, (int) config('fiscal.webhook.unknown_rate_limit_per_minute', 60)))
                    ->by('fiscal-webhook:unknown');
            }

            $sent = (string) $r->header((string) config('fiscal.webhook.header', 'X-FocusNfe-Secret'), '');

            if ((string) $match->webhook_secret === '' || ! hash_equals((string) $match->webhook_secret, $sent)) {
                return Limit::perMinute(30)->by('fiscal-webhook:invalid:' . sha1($token));
            }

            return Limit::perMinute(max(1, (int) config('fiscal.webhook.rate_limit_per_minute', 120)))
                ->by('fiscal-webhook:' . sha1($token));
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

        // Autosave do prontuário: ~2/min por aba no uso normal (a cada 30 s +
        // ao ocultar a aba) — folga para várias abas, teto contra laço.
        RateLimiter::for('medical-record-drafts', static function (Request $r) {
            return Limit::perMinute(60)->by('medical-record-drafts|' . ($r->user()?->id ?? $r->ip()));
        });

        // Ações da equipe sobre os retornos (contato, adiar, dispensar, reabrir, "feito fora"): uso humano, teto
        // por usuário contra laço de script.
        RateLimiter::for('recall-write', static function (Request $r) {
            return Limit::perMinute(90)->by('recall-write|' . ($r->user()?->id ?? $r->ip()));
        });

        // Convite de retorno por WhatsApp mandado por uma pessoa (prévia e envio): cada clique pode gastar o teto do dia
        // da clínica e a qualidade do número — teto por usuário bem abaixo das demais ações da tela.
        RateLimiter::for('recall-invite', static function (Request $r) {
            return Limit::perMinute(30)->by('recall-invite|' . ($r->user()?->id ?? $r->ip()));
        });

        // Página PÚBLICA do Revisor Clínico (Fase 6, ADR 0002): sem login, o token do
        // link é o segredo. O 1º salto (token na URL) e o PIN têm teto por IP contra
        // varredura e adivinhação; as demais rotas, por IP + convite da sessão.
        $reviewKey = static fn (Request $r): string => $r->ip() . '|' . ($r->session()->get('clinical_review.invitation_id') ?? 'none');

        RateLimiter::for('clinical-review-open', static fn (Request $r) => Limit::perMinute(20)->by('clinical-review-open|' . $r->ip()));
        RateLimiter::for('clinical-review-view', static fn (Request $r) => Limit::perMinute(60)->by('clinical-review-view|' . $reviewKey($r)));
        RateLimiter::for('clinical-review-code', static fn (Request $r) => Limit::perMinute(10)->by('clinical-review-code|' . $reviewKey($r)));
        RateLimiter::for('clinical-review-draft', static fn (Request $r) => Limit::perMinute(40)->by('clinical-review-draft|' . $reviewKey($r)));
        RateLimiter::for('clinical-review-submit', static fn (Request $r) => Limit::perMinute(6)->by('clinical-review-submit|' . $reviewKey($r)));

        // Laudo pro médico solicitante (Fase 5): rota pública, sem sessão/
        // usuário — o link já é assinado (HMAC, impossível de forjar); o
        // throttle é só defesa extra contra varredura/compartilhamento em
        // massa do link, por IP.
        RateLimiter::for('external-report-view', static function (Request $r) {
            return Limit::perMinute(10)->by('external-report-view|' . $r->ip());
        });
    }

    /**
     * F0 "Enxergar": torna N+1 e query lenta visíveis sem mudar comportamento. Nada aqui lança nem loga dado de
     * paciente: só nome de model/relação, conexão, duração e rota/comando/job (nunca SQL, bindings ou URL completa).
     */
    private function bootObservability(): void
    {
        // Lazy loading proibido fora de produção, mas o callback só REPORTA: a dívida de N+1 aparece sem quebrar tela
        // nem teste. Em testing só loga (sem report()/Sentry). O framework só marca como "N+1" o model hidratado numa
        // coleção de 2+ itens; um model sozinho (find/first) nunca chega aqui.
        Model::preventLazyLoading(! $this->app->isProduction());

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            if ($this->app->environment('testing')) {
                Log::warning('eloquent.lazy_loading_violation', ['model' => $model::class, 'relation' => $relation]);

                return;
            }

            report(new LazyLoadingViolationException($model, $relation));
        });

        // Nome do job (nunca o payload) no contexto dos logs enquanto ele roda.
        Queue::before(fn (JobProcessing $event) => Context::add('job', $event->job->resolveName()));
        Queue::after(fn () => Context::forget('job'));
        Queue::failing(fn () => Context::forget('job'));

        // Query lenta: o gatilho é a duração ACUMULADA da conexão (roda uma vez por conexão/processo), por isso o log
        // traz o total e a query corrente. Só metadados — o SQL e os bindings podem carregar dado clínico.
        DB::whenQueryingForLongerThan(500, function (Connection $connection, QueryExecuted $query): void {
            $console = $this->app->runningInConsole();

            Log::warning('db.slow_query', [
                'connection'        => $connection->getName(),
                'duration_ms'       => round((float) $query->time, 1),
                'total_duration_ms' => round($connection->totalQueryDuration(), 1),
                'route'             => $console ? null : request()->route()?->getName(),
                'command'           => $console ? ($_SERVER['argv'][1] ?? null) : null,
                'job'               => Context::get('job'),
            ]);
        });
    }
}

<?php

use App\Actions\Register\RegisterAction;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting, TermVersion, User, UserTermAcceptance};
use App\Services\{TermsService, TrialService};
use Illuminate\Auth\Events\Registered;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\{Cache, Event};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Payload válido para POST /register.
 * Sobrescreva apenas os campos que interessam ao cenário.
 */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'João Silva',
        'email'                 => 'joao@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'company_name'          => 'Clínica Silva',
        'company_phone'         => '11988887777',
        'company_cnpj'          => null,
        'plan_id'               => null,
    ], $overrides);
}

/**
 * Persiste trial_days e limpa o cache usado por SubscriptionSetting::trialDays().
 */
function setTrialDays(int $days): void
{
    Cache::forget('subscription_setting:trial_days');
    SubscriptionSetting::setValue('trial_days', $days);
    Cache::forget('subscription_setting:trial_days');
}

// ── Testes ────────────────────────────────────────────────────────────────────

describe('Fluxo de registro', function () {
    beforeEach(function () {
        // Bloqueia apenas o evento Registered (envio de email) sem interceptar eventos de modelo
        Event::fake([Registered::class]);

        // Trial de 14 dias como padrão em todos os testes deste grupo
        setTrialDays(14);
    });

    // ── Cenário 1: happy path com plano escolhido ─────────────────────────────

    it('cria usuário, empresa, vínculo e assinatura em trial ao escolher um plano', function () {
        $plan = Plan::factory()->create(['sort_order' => 1]);

        $response = $this->postJson('/register', registrationPayload([
            'plan_id' => $plan->id,
        ]));

        $response->assertOk()->assertJsonStructure(['redirect']);

        // Exatamente um registro de cada entidade principal
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('entities', 1);
        $this->assertDatabaseCount('entity_users', 1);
        $this->assertDatabaseCount('subscriptions', 1);

        $user   = User::where('email', 'joao@example.com')->firstOrFail();
        $entity = $user->entityUsers()->with('entity')->first()->entity;

        // Vínculo entre usuário e empresa
        $this->assertDatabaseHas('entity_users', [
            'user_id'   => $user->id,
            'entity_id' => $entity->id,
            'rule'      => 'admin',
            'active'    => true,
        ]);

        // Assinatura em trial com o plano correto e prazo de 14 dias
        $subscription = Subscription::where('entity_id', $entity->id)->firstOrFail();

        expect($subscription->plan_id)->toBe($plan->id)
            ->and($subscription->status)->toBe(SubscriptionStatus::Trial)
            ->and($subscription->trial_ends_at)->not->toBeNull()
            ->and($subscription->trial_ends_at->isFuture())->toBeTrue()
            ->and(now()->diffInDays($subscription->trial_ends_at))->toBeBetween(13, 14);
    });

    // ── Cenário 2: sem plan_id → fallback para menor tier ────────────────────

    it('devolve o CSRF renovado pelo login para continuar o checkout sem recarregar', function (string $mode) {
        $plan     = Plan::factory()->create(['sort_order' => 1]);
        $oldToken = str_repeat('a', 40);

        $response = $this->withSession(['_token' => $oldToken])->postJson('/register', registrationPayload([
            'plan_id'    => $plan->id,
            'start_mode' => $mode,
        ]));

        $response->assertOk()->assertJsonStructure(['csrf_token']);
        $newToken = $response->json('csrf_token');
        expect($newToken)->toBeString()->toHaveLength(40)->not->toBe($oldToken);
        $response->assertSessionHas('_token', $newToken);
        $this->assertAuthenticated();
    })->with(['trial', 'checkout']);

    it('[SEGURANÇA] a resposta que carrega o CSRF da sessão nunca vai para cache (no-store) — nos dois modos', function (string $mode) {
        $plan = Plan::factory()->create(['sort_order' => 1]);

        $response = $this->postJson('/register', registrationPayload([
            'plan_id'    => $plan->id,
            'start_mode' => $mode,
        ]))->assertOk()->assertJsonStructure(['csrf_token']);

        $cacheControl = (string) $response->headers->get('Cache-Control');

        expect($cacheControl)->toContain('no-store')
            ->and($cacheControl)->toContain('private');
    })->with(['trial', 'checkout']);

    it('[SEGURANÇA] o CSRF devolvido no corpo do /register não vai para nenhum log da requisição', function (string $mode) {
        $plan   = Plan::factory()->create(['sort_order' => 1]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . ' ' . json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        $token = $this->postJson('/register', registrationPayload([
            'plan_id'    => $plan->id,
            'start_mode' => $mode,
        ]))->assertOk()->json('csrf_token');

        expect($token)->toBeString()->not->toBeEmpty();

        foreach ($logged as $line) {
            expect(str_contains($line, $token))->toBeFalse('o token CSRF apareceu num log: ' . substr($line, 0, 200));
        }
    })->with(['trial', 'checkout']);

    it('cria assinatura usando o menor plano ativo quando nenhum plano é especificado', function () {
        $cheaper = Plan::factory()->create(['sort_order' => 1]);
        $pricier = Plan::factory()->create(['sort_order' => 5]);

        $response = $this->postJson('/register', registrationPayload(['plan_id' => null]));

        $response->assertOk();

        $entity = User::where('email', 'joao@example.com')->first()
            ->entityUsers->first()->entity;
        $subscription = Subscription::where('entity_id', $entity->id)->firstOrFail();

        expect($subscription->plan_id)->toBe($cheaper->id)
            ->and($subscription->status)->toBe(SubscriptionStatus::Trial);

        expect($subscription->plan_id)->not->toBe($pricier->id);
    });

    // ── Cenário 3: falha se plano não existir ────────────────────────────────

    it('falha com 422 se o plan_id informado não existir', function () {
        $response = $this->postJson('/register', registrationPayload([
            'plan_id' => '00000000-0000-0000-0000-000000000000',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        // Nenhum registro deve ter sido criado
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    });

    // ── Cenário 4: trial desabilitado (trial_days = 0) ────────────────────────

    it('orienta contato sem executar o cadastro quando trial está desabilitado', function (int $days) {
        Plan::factory()->create(['sort_order' => 1]);
        setTrialDays($days);
        $this->mock(RegisterAction::class)->shouldNotReceive('execute');

        $response = $this->postJson('/register', registrationPayload());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id')
            ->assertJsonPath('errors.plan_id.0', __('auth.register.trial_unavailable'));

        // A criação nem começou — zero registros parciais.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('entities', 0);
        $this->assertDatabaseCount('entity_users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    })->with([0, -1]);

    // ── Cenário 5: falha se email já existir ─────────────────────────────────

    it('falha com 422 se o email já estiver cadastrado', function () {
        User::factory()->create(['email' => 'joao@example.com']);

        $response = $this->postJson('/register', registrationPayload());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        // Usuário pré-existente não deve ser duplicado; nenhuma empresa criada
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('entities', 0);
    });

    // ── Cenário 6: tudo deve rodar em transação ───────────────────────────────

    it('reverte todos os registros se qualquer etapa da transação falhar', function () {
        Plan::factory()->create(['sort_order' => 1]);

        // Simula falha no serviço de trial (ocorre depois que User e Entity já foram criados)
        $this->mock(TrialService::class)
            ->shouldReceive('startManualTrial')
            ->andThrow(new RuntimeException('Falha simulada no gateway'));

        $response = $this->postJson('/register', registrationPayload());

        $response->assertServerError();

        // Nenhum registro parcial deve persistir
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('entities', 0);
        $this->assertDatabaseCount('entity_users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    });

    // ── Cenário 7: criador deve ser owner da empresa ──────────────────────────

    it('marca o criador da empresa como is_owner = true no vínculo', function () {
        Plan::factory()->create(['sort_order' => 1]);

        $this->postJson('/register', registrationPayload())->assertOk();

        $user   = User::where('email', 'joao@example.com')->firstOrFail();
        $entity = $user->entityUsers->first()->entity;

        $this->assertDatabaseHas('entity_users', [
            'user_id'   => $user->id,
            'entity_id' => $entity->id,
            'rule'      => 'admin',
            'is_owner'  => true,
        ]);
    });

    // ── Comportamentos complementares ────────────────────────────────────────

    it('autentica o usuário imediatamente após o registro', function () {
        Plan::factory()->create(['sort_order' => 1]);

        $this->postJson('/register', registrationPayload())->assertOk();

        $this->assertAuthenticated();
    });

    it('armazena entity_id e rule do usuário na sessão', function () {
        Plan::factory()->create(['sort_order' => 1]);

        $response = $this->postJson('/register', registrationPayload())->assertOk();

        $response->assertSessionHas('selected_entity_id')
            ->assertSessionHas('selected_entity_user_rule', 'admin')
            ->assertSessionHas('user_rule', 'admin');
    });

    it('retorna a URL de redirecionamento para o painel', function () {
        Plan::factory()->create(['sort_order' => 1]);

        $this->postJson('/register', registrationPayload())
            ->assertOk()
            ->assertJson(['redirect' => route('panel.dashboard', absolute: false)]);
    });

    it('falha com 422 se o CNPJ já estiver cadastrado em outra empresa', function () {
        Plan::factory()->create(['sort_order' => 1]);

        // Primeira empresa — sucesso
        $this->postJson('/register', registrationPayload([
            'email'        => 'primeira@example.com',
            'company_cnpj' => '12345678000195',
        ]))->assertOk();

        // Faz logout para testar o fluxo como visitante
        auth()->logout();

        // Segunda tentativa com o mesmo CNPJ — deve falhar
        $this->postJson('/register', registrationPayload([
            'email'        => 'segunda@example.com',
            'company_cnpj' => '12345678000195',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('company_cnpj');
    });

    // BUG de regressão — 500 (FK 23503 em audit_logs) quando a sessão
    // carregava um selected_entity_id de entity já excluída (empresa de
    // teste apagada no manager, banco re-seedado). AuditService agora
    // preserva a trilha com entity_id NULL em vez de derrubar o registro.
    it('não quebra quando a sessão aponta para uma entity inexistente', function () {
        Plan::factory()->create(['sort_order' => 1]);

        $this->withSession(['selected_entity_id' => (string) Str::uuid()])
            ->postJson('/register', registrationPayload())
            ->assertOk();

        $user = User::where('email', 'joao@example.com')->firstOrFail();

        // Trilha preservada: evento created do User gravado, com entity_id null
        // (a entity fantasma da sessão não contamina o log).
        $log = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->where('event', 'created')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->entity_id)->toBeNull();
    });
});

// ── Aceite dos Termos de Uso e da Política de Privacidade ────────────────────

/**
 * Publica a versão de um documento obrigatório (term_versions). Os testes rodam sem seed (tests/Pest.php): o que
 * está "vigente" é decidido aqui. Padrão: v1.0 de cada tipo, em vigor desde ontem.
 */
function publishTerms(string $type, string $version = '1.0', ?string $effectiveFrom = null): TermVersion
{
    return TermVersion::query()->create([
        'type'           => $type,
        'version'        => $version,
        'content'        => "Texto oficial de {$type} v{$version}",
        'effective_from' => $effectiveFrom ?? now()->subDay()->toDateString(),
        'active'         => true,
    ]);
}

/** UUIDs das versões que a tela exibiu (o que o POST manda em terms_version_ids). */
function termsIds(TermVersion ...$versions): array
{
    return array_map(fn (TermVersion $version) => (string) $version->id, $versions);
}

describe('Aceite dos termos no cadastro', function () {
    beforeEach(function () {
        Event::fake([Registered::class]);
        setTrialDays(14);
        Plan::factory()->create(['sort_order' => 1]);
    });

    it('com termos publicados e terms_accepted=true: grava 1 aceite por documento vigente, com versão, data, IP e navegador', function () {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 30));
        $terms   = publishTerms('terms_of_service');
        $privacy = publishTerms('privacy_policy');

        $this->withHeader('User-Agent', 'EasyEyeTest/1.0')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($terms, $privacy)]))
            ->assertOk();

        $user = User::where('email', 'joao@example.com')->firstOrFail();

        expect(UserTermAcceptance::query()->count())->toBe(2);

        foreach ([$terms, $privacy] as $version) {
            $acceptance = UserTermAcceptance::query()->where('user_id', $user->id)->where('term_version_id', $version->id)->sole();

            expect($acceptance->accepted_at->toDateTimeString())->toBe('2026-10-15 10:30:00')
                ->and($acceptance->ip_address)->toBe('203.0.113.7')
                ->and($acceptance->user_agent)->toBe('EasyEyeTest/1.0');
        }

        expect(app(TermsService::class)->hasAcceptedAll($user))->toBeTrue();
    });

    it('[RESSALVA] com termos PUBLICADOS, cadastro sem aceite (ausente, false ou null) é 422 em terms_accepted e não cria NADA', function (array $extra) {
        publishTerms('terms_of_service');
        publishTerms('privacy_policy');

        $this->postJson('/register', registrationPayload($extra))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted')
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_required'));

        expect(User::count())->toBe(0)
            ->and(Entity::count())->toBe(0)
            ->and(UserTermAcceptance::count())->toBe(0);
    })->with([
        'ausente' => [[]],
        'false'   => [['terms_accepted' => false]],
        'null'    => [['terms_accepted' => null]],
        'zero'    => [['terms_accepted' => 0]],
    ]);

    it('com só um documento vigente (a política), o aceite dele já é exigido e gravado', function () {
        $privacy = publishTerms('privacy_policy');

        $this->postJson('/register', registrationPayload())->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($privacy)]))->assertOk();

        expect(UserTermAcceptance::query()->sole()->term_version_id)->toBe($privacy->id);
    });

    it('sem versão vigente publicada: o cadastro NÃO é bloqueado, nada é gravado e o aviso terms.required_version_missing vai ao log', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = [$event->level, $event->message, $event->context['type'] ?? null];
        });

        // terms_accepted é ignorado quando não há o que aceitar (marcado ou não).
        $this->postJson('/register', registrationPayload())->assertOk();

        // Um aviso POR tipo obrigatório ausente.
        expect(User::count())->toBe(1)
            ->and(UserTermAcceptance::count())->toBe(0)
            ->and($logged)->toContain(['warning', 'terms.required_version_missing', 'privacy_policy'])
            ->and($logged)->toContain(['warning', 'terms.required_version_missing', 'terms_of_service']);

        auth()->logout();
        $this->postJson('/register', registrationPayload(['email' => 'outro@example.com', 'terms_accepted' => true, 'company_phone' => '11977776666']))->assertOk();

        expect(User::count())->toBe(2)->and(UserTermAcceptance::count())->toBe(0);
    });

    it('versão com vigência futura não conta: o aceite aponta a versão VIGENTE anterior', function () {
        $current = publishTerms('terms_of_service', '1.0');
        publishTerms('terms_of_service', '2.0', now()->addDays(10)->toDateString());
        publishTerms('privacy_policy', '1.0', now()->addDay()->toDateString()); // só futura: sem versão vigente

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($current)]))->assertOk();

        expect(UserTermAcceptance::query()->sole()->term_version_id)->toBe($current->id);
    });

    it('o aceite nasce na MESMA transação do cadastro: falha no 2º aceite desfaz usuário, clínica e o 1º aceite', function () {
        $terms   = publishTerms('terms_of_service');
        $privacy = publishTerms('privacy_policy');

        $calls = 0;
        $this->partialMock(TermsService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('accept')->andReturnUsing(function (User $user, TermVersion $version) use (&$calls) {
                if (++$calls === 2) {
                    throw new RuntimeException('falha no 2º aceite');
                }

                return UserTermAcceptance::query()->create(['user_id' => $user->id, 'term_version_id' => $version->id, 'accepted_at' => now()]);
            });
        });
        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($terms, $privacy)])))
            ->toThrow(RuntimeException::class, 'falha no 2º aceite');

        expect($calls)->toBe(2)
            ->and(User::count())->toBe(0)
            ->and(Entity::count())->toBe(0)
            ->and(UserTermAcceptance::count())->toBe(0);
    });

    it('[S2-1] com UM dos documentos sem versão vigente: aviso para o tipo ausente, aceite do que existe, cadastro não bloqueado', function () {
        $terms  = publishTerms('terms_of_service');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            if ($event->message === 'terms.required_version_missing') {
                $logged[] = [$event->level, $event->context['type'] ?? null];
            }
        });

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($terms)]))->assertOk();

        expect($logged)->toBe([['warning', 'privacy_policy']]) // só o tipo que falta
            ->and(UserTermAcceptance::query()->sole()->term_version_id)->toBe($terms->id);
    });

    it('[S2-1] com os dois documentos vigentes não há aviso', function () {
        $terms   = publishTerms('terms_of_service');
        $privacy = publishTerms('privacy_policy');
        $logged  = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($terms, $privacy)]))->assertOk();

        expect($logged)->not->toContain('terms.required_version_missing');
    });

    it('[S2-2] GET /register mostra a v1, a v2 é publicada, o POST com os ids da v1 é 422 (terms_changed) e NADA é gravado', function () {
        $v1Terms   = publishTerms('terms_of_service', '1.0');
        $v1Privacy = publishTerms('privacy_policy', '1.0');

        $shown = collect(AssertableInertia::fromTestResponse($this->get(route('register')))->toArray()['props']['terms']['documents'])->pluck('version_id')->all();
        expect($shown)->toEqualCanonicalizing(termsIds($v1Terms, $v1Privacy));

        $this->travel(1)->day();
        $v2 = app(TermsService::class)->publish('terms_of_service', '2.0', 'Texto novo', now()->toDateString(), User::factory()->create());

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => $shown]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted')
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_changed'));

        expect(User::where('email', 'joao@example.com')->count())->toBe(0)
            ->and(Entity::count())->toBe(0)
            ->and(UserTermAcceptance::count())->toBe(0);

        // Com os ids que a tela passa a mostrar (v2 + política), o aceite vale e grava as versões vigentes.
        $shownNow = collect(AssertableInertia::fromTestResponse($this->get(route('register')))->toArray()['props']['terms']['documents'])->pluck('version_id')->all();
        expect($shownNow)->toEqualCanonicalizing([$v2->id, (string) $v1Privacy->id]);

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => $shownNow]))->assertOk();

        expect(UserTermAcceptance::query()->pluck('term_version_id')->all())->toEqualCanonicalizing([$v2->id, (string) $v1Privacy->id])
            ->and(UserTermAcceptance::query()->where('term_version_id', $v1Terms->id)->count())->toBe(0);
    });

    it('[S2-2] terms_version_ids tem de ser EXATAMENTE o conjunto vigente: ausente, parcial ou com id extra é 422 terms_changed; ordem e repetição não importam', function (string $case) {
        $terms   = publishTerms('terms_of_service');
        $privacy = publishTerms('privacy_policy');

        $ids = match ($case) {
            'ausente'    => [],
            'parcial'    => termsIds($terms),
            'extra'      => [...termsIds($terms, $privacy), (string) Str::uuid()],
            'outro'      => [(string) Str::uuid(), (string) Str::uuid()],
            'ordem'      => array_reverse(termsIds($terms, $privacy)),
            'repetidos'  => [...termsIds($terms, $privacy), ...termsIds($terms)],
            'maiusculas' => array_map('strtoupper', termsIds($terms, $privacy)),
        };
        $payload = registrationPayload(['terms_accepted' => true] + ($case === 'ausente' ? [] : ['terms_version_ids' => $ids]));

        if (in_array($case, ['ordem', 'repetidos', 'maiusculas'], true)) {
            $this->postJson('/register', $payload)->assertOk();
            expect(UserTermAcceptance::count())->toBe(2);

            return;
        }

        // A barreira é a do request: o RegisterAction nem chega a rodar (a 2ª conferência, dentro da transação, é só para a corrida).
        $this->mock(RegisterAction::class)->shouldNotReceive('execute');

        $this->postJson('/register', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_changed'));

        expect(User::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    })->with(['ausente', 'parcial', 'extra', 'outro', 'ordem', 'repetidos', 'maiusculas']);

    it('[S2-2] terms_version_ids malformado (não é lista de UUIDs) é 422 de validação e nada é gravado', function (mixed $ids) {
        publishTerms('terms_of_service');
        publishTerms('privacy_policy');

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => $ids]))->assertUnprocessable();

        expect(User::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    })->with([
        'texto'        => ['nao-e-lista'],
        'nao uuid'     => [['abc']],
        'objeto'       => [['a' => ['b']]],
        'lista enorme' => [array_fill(0, 11, '11111111-1111-4111-8111-111111111111')],
    ]);

    it('[S2-2] versão publicada ENTRE a validação e a gravação (corrida): o aceite é conferido de novo na transação — 422 e nada fica', function () {
        $v1Terms   = publishTerms('terms_of_service', '1.0');
        $v1Privacy = publishTerms('privacy_policy', '1.0');
        $v2Terms   = publishTerms('terms_of_service', '2.0');

        $viewV1 = ['terms_of_service' => $v1Terms, 'privacy_policy' => $v1Privacy];
        $nowV2  = ['terms_of_service' => $v2Terms, 'privacy_policy' => $v1Privacy];

        // 1ª leitura (a validação do request) ainda vê a v1; a 2ª (dentro da transação) já vê a v2.
        $this->partialMock(TermsService::class, fn ($mock) => $mock->shouldReceive('currentRequired')->twice()->andReturn($viewV1, $nowV2));

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($v1Terms, $v1Privacy)]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_changed'));

        expect(User::count())->toBe(0)->and(Entity::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    });

    it('[S2-2 re] corrida [v1] → [] (a versão é trocada e o request viu a v1, a transação já vê NADA): 422 terms_changed e nada fica', function () {
        $v1 = publishTerms('terms_of_service');

        $this->partialMock(TermsService::class, fn ($mock) => $mock->shouldReceive('currentRequired')->twice()->andReturn(['terms_of_service' => $v1], []));

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($v1)]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_changed'));

        expect(User::count())->toBe(0)->and(Entity::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    });

    it('[S2-2 re] corrida [] → [v1] (o request viu NADA publicado, a versão surge antes da transação): 422 terms_changed e nada fica — com ou sem terms_accepted', function (array $extra) {
        $v1 = publishTerms('terms_of_service');

        $this->partialMock(TermsService::class, fn ($mock) => $mock->shouldReceive('currentRequired')->twice()->andReturn([], ['terms_of_service' => $v1]));

        $this->postJson('/register', registrationPayload($extra))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_changed'));

        expect(User::count())->toBe(0)->and(Entity::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    })->with([
        'sem aceite'   => [[]],
        'false'        => [['terms_accepted' => false]],
        'true sem ids' => [['terms_accepted' => true]],
    ]);

    it('[S2-2 re] corrida [] → [v1] com os ids certos mas SEM aceite: 422 terms_required (a transação exige terms_accepted verdadeiro) e nada fica', function () {
        $v1 = publishTerms('terms_of_service');

        $this->partialMock(TermsService::class, fn ($mock) => $mock->shouldReceive('currentRequired')->twice()->andReturn([], ['terms_of_service' => $v1]));

        $this->postJson('/register', registrationPayload(['terms_accepted' => false, 'terms_version_ids' => termsIds($v1)]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terms_accepted.0', __('auth.register.terms_required'));

        expect(User::count())->toBe(0)->and(Entity::count())->toBe(0)->and(UserTermAcceptance::count())->toBe(0);
    });

    it('[S2-2 re] sem versão publicada nos dois momentos, o cadastro segue normal (conferência de vazio == vazio)', function () {
        $this->postJson('/register', registrationPayload())->assertOk();

        expect(User::count())->toBe(1)->and(UserTermAcceptance::count())->toBe(0);
    });

    it('usuários que já existiam ficam intocados: publicar os termos e cadastrar alguém não cria aceite para eles', function () {
        $existing = User::factory()->create();
        $terms    = publishTerms('terms_of_service');
        $privacy  = publishTerms('privacy_policy');

        $this->postJson('/register', registrationPayload(['terms_accepted' => true, 'terms_version_ids' => termsIds($terms, $privacy)]))->assertOk();

        expect(UserTermAcceptance::query()->where('user_id', $existing->id)->count())->toBe(0)
            ->and(app(TermsService::class)->hasAcceptedAll($existing))->toBeFalse() // sem retroação: a prova não foi inventada
            ->and(UserTermAcceptance::count())->toBe(2);
    });
});

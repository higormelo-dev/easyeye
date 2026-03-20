<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSetting;
use App\Models\User;
use App\Services\TrialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

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
        Event::fake([\Illuminate\Auth\Events\Registered::class]);

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

    it('cria assinatura usando o menor plano ativo quando nenhum plano é especificado', function () {
        $cheaper = Plan::factory()->create(['sort_order' => 1]);
        $pricier = Plan::factory()->create(['sort_order' => 5]);

        $response = $this->postJson('/register', registrationPayload(['plan_id' => null]));

        $response->assertOk();

        $entity       = User::where('email', 'joao@example.com')->first()
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

    it('falha e reverte a transação quando trial está desabilitado (trial_days = 0)', function () {
        Plan::factory()->create(['sort_order' => 1]);
        setTrialDays(0);

        $response = $this->postJson('/register', registrationPayload());

        $response->assertServerError();

        // Transação completamente revertida — zero registros parciais
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('entities', 0);
        $this->assertDatabaseCount('entity_users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    });

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
            ->andThrow(new \RuntimeException('Falha simulada no gateway'));

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
});

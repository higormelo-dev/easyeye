<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiRun};
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 100)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->admin   = User::factory()->create();
    $this->adminEU = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function aiRunFor(Entity $entity, User $user, AiRunStatus $status, int $consumed, string $workflow = 'report_drafting'): AiRun
{
    return AiRun::query()->create([
        'entity_id'         => $entity->id,
        'requested_by'      => $user->id,
        'workflow'          => $workflow,
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => $status->value,
        'estimated_credits' => $consumed,
        'reserved_credits'  => $consumed,
        'consumed_credits'  => $consumed,
    ]);
}

test('dashboard responde Inertia para membro autorizado', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertOk();
    $response->assertJsonPath('component', 'Panel/AI/Dashboard');
});

test('dashboard expõe plan_quota lida da feature do plano', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertJsonPath('props.plan_quota', 100);
});

test('dashboard agrega consumo mensal pelo ledger (type=consume) no período corrente', function () {
    // Run com consumed_credits alto NÃO deve influenciar o dashboard quando não
    // existe lançamento consume correspondente no ledger no período.
    $run = aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 999);

    $wallet = app(AiCreditWalletService::class);

    $wallet->reserve(
        entityId: $this->entity->id,
        amount: 20,
        aiRunId: (string) $run->id,
        idempotencyKey: 'dash-ledger-reserve-old',
    );
    $oldConsume = $wallet->consumeReservation(
        entityId: $this->entity->id,
        amount: 20,
        aiRunId: (string) $run->id,
        idempotencyKey: 'dash-ledger-consume-old',
    );

    // Simula consumo fora do mês corrente.
    AiCreditLedgerEntry::query()
        ->whereKey($oldConsume->id)
        ->update([
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

    $wallet->reserve(
        entityId: $this->entity->id,
        amount: 45,
        aiRunId: (string) $run->id,
        idempotencyKey: 'dash-ledger-reserve-current',
    );
    $wallet->consumeReservation(
        entityId: $this->entity->id,
        amount: 45,
        aiRunId: (string) $run->id,
        idempotencyKey: 'dash-ledger-consume-current',
    );

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertJsonPath('props.consumed.this_month', 45);
    $response->assertJsonPath('props.consumed.usage_percent', 45);
});

test('dashboard distribui consumo por workflow', function () {
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 40, 'report_drafting');
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 10, 'exam_assistant');

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $by_workflow = $response->json('props.by_workflow');

    expect($by_workflow)->toHaveCount(2);
    expect($by_workflow[0]['workflow'])->toBe('report_drafting');
    expect($by_workflow[0]['credits'])->toBe(40);
    // JSON serializa 80.0 como 80 (sem zero decimal). Comparação loose via toEqual.
    expect($by_workflow[0]['percent'])->toEqual(80.0);
    expect($by_workflow[1]['workflow'])->toBe('exam_assistant');
    expect($by_workflow[1]['percent'])->toEqual(20.0);
});

test('dashboard agrega consumo por modo sem expor provedores internos', function () {
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 30);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 10);

    AiRun::query()->latest('created_at')->firstOrFail()->update([
        'mode'             => AiRunMode::Consensus->value,
        'consumed_credits' => 10,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertJsonMissingPath('props.by_provider');

    $byMode = collect($response->json('props.by_mode'))->keyBy('mode');

    expect($byMode[AiRunMode::Validated->value]['runs'])->toBe(1);
    expect($byMode[AiRunMode::Validated->value]['credits'])->toBe(30);
    expect($byMode[AiRunMode::Consensus->value]['runs'])->toBe(1);
    expect($byMode[AiRunMode::Consensus->value]['credits'])->toBe(10);
});

test('dashboard calcula approval_rate considerando só runs decididos', function () {
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 10);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 10);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 10);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Rejected, 5);
    aiRunFor($this->entity, $this->admin, AiRunStatus::WaitingApproval, 5);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Failed, 0);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertJsonPath('props.approval.approved', 3);
    $response->assertJsonPath('props.approval.rejected', 1);
    $response->assertJsonPath('props.approval.waiting', 1);
    $response->assertJsonPath('props.approval.failed', 1);
    // approval_rate = approved / (approved + rejected) = 3/4 = 75% (loose via toEqual).
    expect($response->json('props.approval.approval_rate'))->toEqual(75.0);
});

test('dashboard top_runs vem ordenado desc por consumed_credits', function () {
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 50);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 5);
    aiRunFor($this->entity, $this->admin, AiRunStatus::Approved, 25);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $top = $response->json('props.top_runs');

    expect($top[0]['credits'])->toBe(50);
    expect($top[1]['credits'])->toBe(25);
    expect($top[2]['credits'])->toBe(5);
});

test('dashboard isola dados por entity (cross-tenant)', function () {
    // Cria outra entity com runs — não devem aparecer no dashboard da entity atual.
    $other     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherUser = User::factory()->create();
    aiRunFor($other, $otherUser, AiRunStatus::Approved, 999);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->get(route('panel.ai-runs.dashboard'), inertiaHeaders());

    $response->assertJsonPath('props.consumed.this_month', 0);
    expect($response->json('props.top_runs'))->toBe([]);
});

test('dashboard bloqueia quando feature de IA não está habilitada no plano', function () {
    $entityWithoutAi = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $planWithoutAi   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->limit(FeatureKey::MaxUsers, 5)->for($planWithoutAi)->create();

    Subscription::factory()->create([
        'entity_id' => $entityWithoutAi->id,
        'plan_id'   => $planWithoutAi->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $admin   = User::factory()->create();
    $adminEU = createEntityUser($entityWithoutAi, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)
        ->withSession(panelSession($adminEU))
        ->getJson(route('panel.ai-runs.dashboard'))
        ->assertForbidden();
});

test('store rate limit bloqueia após exceder o limite por minuto', function () {
    // Override config: limite agressivo para evitar disparar muitos requests.
    config()->set('ai.rate_limits.store_per_minute', 2);

    $payload = baseRunPayload();
    $session = panelSession($this->adminEU);

    // 2 requests devem passar (podem retornar 422 por saldo, 201, ou 400 — não importa pro teste).
    for ($i = 0; $i < 2; $i++) {
        $response = $this->actingAs($this->admin)->withSession($session)
            ->postJson(route('panel.ai-runs.store'), $payload);
        expect($response->status())->not->toBe(429);
    }

    // 3ª deve receber 429 Too Many Requests.
    $response = $this->actingAs($this->admin)->withSession($session)
        ->postJson(route('panel.ai-runs.store'), $payload);

    expect($response->status())->toBe(429);
});

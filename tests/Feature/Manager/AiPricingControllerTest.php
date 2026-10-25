<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiExchangeRate, AiRun};
use App\Domains\AI\Services\{AiExchangeRateService, AiPricingSettings};
use App\Enums\AI\AiRunStatus;
use App\Enums\SaasRule;
use App\Models\{AuditLog, Entity, User};
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Log};

/*
 * Manager → IA → Precificação: só dono/admin financeiro do SaaS; toda escrita exige motivo, é auditada e o histórico de
 * câmbio é append-only. É a ÚNICA tela onde custo do provedor, câmbio e markup aparecem (plano ai-credit-packs-brl §4).
 */

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true, 'name' => 'EasyEye']);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
});

function pricingSession(Entity $saas, string $rule): array
{
    return ['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule];
}

function asPricingUser(?User $user = null, string $rule = SaasRule::Admin->value)
{
    return test()->actingAs($user ?? test()->admin)->withSession(pricingSession(test()->saas, $rule));
}

it('mostra câmbio (com aviso de fallback), markup, pisos, pacotes e a conta de exemplo', function () {
    config(['ai.pricing.markup_multiplier' => 5.0]);

    $props = asPricingUser()->get(route('manager.ai-pricing.index'))->assertOk()->viewData('page')['props'];

    expect($props['rate'])->toMatchArray(['rate' => 5.5, 'source' => 'fallback', 'is_fallback' => true, 'fetched_today' => false, 'is_automatic' => false, 'is_manual_override' => false, 'age_days' => null, 'fetched_at' => null])
        ->and($props['settings'])->toMatchArray(['markup_multiplier' => 5.0, 'minimum_charge_cents_default' => 10, 'custom_min_cents' => 100000, 'custom_max_cents' => 1000000])
        ->and(collect($props['packages'])->pluck('code')->all())->toBe(['basic', 'premium', 'enterprise', 'custom'])
        // US$ 1,00 × 5,50 × 5 = R$ 27,50
        ->and(collect($props['examples'])->firstWhere('raw_cost_usd', 1.0))->toMatchArray(['charge_cents' => 2750, 'charge_formatted' => 'R$ 27,50', 'minimum_applied' => false, 'markup_multiplier' => 5.0, 'exchange_rate_brl_usd' => 5.5])
        ->and($props['history']['data'])->toBe([]);
});

function fakeFrankfurter(float $rate = 5.4321): void
{
    config(['ai.exchange_rate.auto_fetch' => true]);
    Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'USD', 'date' => now()->toDateString(), 'rates' => ['BRL' => $rate]])]);
}

it('mostra o markup por ação (só overrides), as internas, a conta das 9 ações e NÃO busca câmbio ao carregar', function () {
    config(['ai.exchange_rate.auto_fetch' => true]);
    Http::preventStrayRequests();
    app(AiPricingSettings::class)->update(['markup_by_workflow' => ['report_drafting' => 8]]);

    $props = asPricingUser()->get(route('manager.ai-pricing.index'))->assertOk()->viewData('page')['props'];

    expect((array) $props['settings']['markup_by_workflow'])->toBe(['report_drafting' => 8.0])
        ->and($props['settings']['internal_workflows'])->toBe(AiPricingSettings::INTERNAL_WORKFLOWS)
        ->and(collect($props['workflow_examples'])->pluck('workflow')->all())->toBe(AiPricingSettings::WORKFLOWS)
        // US$ 0,01 × 5,50 × 8 = 44 centavos; com o global 5x = 27,5 → 28
        ->and(collect($props['workflow_examples'])->firstWhere('workflow', 'report_drafting'))->toMatchArray(['workflow_label' => 'Rascunho de laudo', 'is_internal' => false, 'raw_cost_usd' => 0.01, 'exchange_rate_brl_usd' => 5.5, 'markup_multiplier' => 8.0, 'minimum_charge_cents' => 10, 'charge_cents' => 44, 'charge_formatted' => 'R$ 0,44', 'minimum_applied' => false])
        ->and(collect($props['workflow_examples'])->firstWhere('workflow', 'medicine_posology'))->toMatchArray(['is_internal' => true, 'markup_multiplier' => 5.0, 'charge_cents' => 28])
        ->and($props['urls']['exchange_rates_fetch'])->toBe(route('manager.ai-pricing.exchange-rates.fetch'));

    Http::assertNothingSent();
});

function pricingUsageRun(string $workflow, ?string $cost, AiRunStatus $status = AiRunStatus::Approved, ?Carbon $createdAt = null, bool $platform = false): AiRun
{
    $run = AiRun::factory()->create([
        // Plataforma = a própria entidade do SaaS (is_client=false; `ai_runs.entity_id` é NOT NULL), como no FinanceController.
        'entity_id'       => $platform ? test()->saas->id : Entity::factory()->create(['is_client' => true])->id,
        'workflow'        => $workflow,
        'status'          => $status->value,
        'actual_cost_usd' => $cost,
    ]);

    if ($createdAt !== null) {
        $run->forceFill(['created_at' => $createdAt])->save();
    }

    return $run;
}

/** @return array<string, mixed> */
function pricingUsageProps(): array
{
    return asPricingUser()->get(route('manager.ai-pricing.index'))->assertOk()->viewData('page')['props']['workflow_usage'];
}

it('manda o uso real dos últimos 30 dias com as 9 ações sempre presentes; sem uso é runs 0 e o resto null', function () {
    $usage = pricingUsageProps();

    expect($usage['window_days'])->toBe(30)
        ->and(array_keys($usage['rows']))->toBe(AiPricingSettings::WORKFLOWS);

    foreach ($usage['rows'] as $row) {
        expect($row)->toBe(['runs' => 0, 'avg_cost_usd' => null, 'avg_price_cents' => null, 'avg_price_formatted' => null, 'minimum_applied' => null]);
    }

    // Contrato antigo intacto: a conta didática (US$ 0,01 fixo) continua na prop própria.
    $props = asPricingUser()->get(route('manager.ai-pricing.index'))->viewData('page')['props'];
    expect(collect($props['workflow_examples'])->pluck('workflow')->all())->toBe(AiPricingSettings::WORKFLOWS)
        ->and(collect($props['workflow_examples'])->firstWhere('workflow', 'exam_assistant'))->toMatchArray(['raw_cost_usd' => 0.01, 'charge_cents' => 28]);
});

it('só execução concluída entra no uso: pending/reserved/running/failed/cancelled ficam de fora', function (AiRunStatus $status, int $runs) {
    // Só quem passou pelo cálculo de preço grava `actual_cost_usd`; as demais ficam com o custo nulo.
    pricingUsageRun('exam_assistant', $runs === 1 ? '0.02000000' : null, $status);

    $row = pricingUsageProps()['rows']['exam_assistant'];

    expect($row['runs'])->toBe($runs)
        ->and($row['avg_cost_usd'])->toBe($runs === 1 ? 0.02 : null);
})->with([
    'pending'          => [AiRunStatus::Pending, 0],
    'reserved'         => [AiRunStatus::Reserved, 0],
    'running'          => [AiRunStatus::Running, 0],
    'failed'           => [AiRunStatus::Failed, 0],
    'cancelled'        => [AiRunStatus::Cancelled, 0],
    'waiting_approval' => [AiRunStatus::WaitingApproval, 1],
    'approved'         => [AiRunStatus::Approved, 1],
    'rejected'         => [AiRunStatus::Rejected, 1], // o custo já foi pago ao provedor
]);

it('a janela é de 30 dias por created_at: 29 dias atrás entra, 31 não', function (int $daysAgo, int $runs) {
    pricingUsageRun('assistant_chat', '0.01000000', createdAt: now()->subDays($daysAgo));

    expect(pricingUsageProps()['rows']['assistant_chat']['runs'])->toBe($runs);
})->with([
    '29 dias' => [29, 1],
    '31 dias' => [31, 0],
]);

it('o preço médio usa o markup da ação e o piso; a média é sobre as execuções concluídas', function () {
    config(['ai.pricing.markup_multiplier' => 5.0]);
    app(AiPricingSettings::class)->update(['markup_by_workflow' => ['report_drafting' => 8]]);

    // Média de US$ 0,005 e US$ 0,015 = US$ 0,01 → × 5,50 × 8 = 44 centavos (com o global 5x seriam 28).
    pricingUsageRun('report_drafting', '0.00500000');
    pricingUsageRun('report_drafting', '0.01500000', AiRunStatus::WaitingApproval);
    pricingUsageRun('report_drafting', null, AiRunStatus::Failed); // fora do denominador
    // Média minúscula: a conta dá menos que o piso (R$ 0,10) → cobra-se o piso e vem marcado.
    pricingUsageRun('exam_assistant', '0.00001000');

    $rows = pricingUsageProps()['rows'];

    expect($rows['report_drafting'])->toBe(['runs' => 2, 'avg_cost_usd' => 0.01, 'avg_price_cents' => 44, 'avg_price_formatted' => 'R$ 0,44', 'minimum_applied' => false])
        ->and($rows['exam_assistant'])->toBe(['runs' => 1, 'avg_cost_usd' => 0.00001, 'avg_price_cents' => 10, 'avg_price_formatted' => 'R$ 0,10', 'minimum_applied' => true])
        ->and($rows['consensus_review']['runs'])->toBe(0);
});

it('plataforma e clínicas entram juntas na média, as internas contam, ação fora da lista é ignorada e nada vai à rede', function () {
    config(['ai.exchange_rate.auto_fetch' => true]);
    Http::preventStrayRequests();

    pricingUsageRun('exam_assistant', '0.02000000');
    pricingUsageRun('exam_assistant', '0.04000000', platform: true); // entidade do SaaS = plataforma
    pricingUsageRun('platform_finance_digest', '0.50000000', platform: true); // interna: custo real, cobrança 0
    pricingUsageRun('exam_report_draft', '0.30000000'); // fora de AiPricingSettings::WORKFLOWS

    $usage = pricingUsageProps();

    expect(array_keys($usage['rows']))->toBe(AiPricingSettings::WORKFLOWS)
        ->and($usage['rows']['exam_assistant'])->toMatchArray(['runs' => 2, 'avg_cost_usd' => 0.03])
        // US$ 0,03 × 5,50 × 5 = 82,5 → 83 centavos
        ->and($usage['rows']['exam_assistant']['avg_price_cents'])->toBe(83)
        ->and($usage['rows']['platform_finance_digest'])->toMatchArray(['runs' => 1, 'avg_cost_usd' => 0.5]);

    Http::assertNothingSent();
});

it('salva o markup por ação com motivo e audita; mapa inválido é 422 com o campo; mapa vazio limpa', function () {
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_by_workflow' => ['report_drafting' => 8, 'medicine_posology' => 2.5], 'reason' => 'Laudo custa mais revisão humana'])
        ->assertOk()
        ->assertJsonPath('settings.markup_by_workflow.report_drafting', 8)
        ->assertJsonPath('settings.markup_by_workflow.medicine_posology', 2.5);

    $settings = app(AiPricingSettings::class);

    expect($settings->markupMultiplierFor('report_drafting'))->toBe(8.0)
        ->and($settings->markupMultiplierFor('exam_assistant'))->toBe(5.0)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.settings_updated')->latest('id')->value('new_values'))->toMatchArray(['markup_by_workflow' => ['report_drafting' => 8.0, 'medicine_posology' => 2.5]]);

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_by_workflow' => ['foo' => 5], 'reason' => 'Ação desconhecida'])
        ->assertStatus(422)->assertJsonPath('field', 'markup_by_workflow');
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_by_workflow' => ['report_drafting' => 0], 'reason' => 'Fora da faixa mínima'])
        ->assertStatus(422)->assertJsonPath('field', 'markup_by_workflow');

    // Mapa vazio limpa os overrides (todas voltam ao global). Faixa 1–20 e demais casos: AiPricingWorkflowMarkupTest.
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_by_workflow' => [], 'reason' => 'Volta todas ao padrão global'])
        ->assertOk();
    expect($settings->markupByWorkflow())->toBe([]);
});

it('"buscar agora" grava a automática com auditoria; repetido no dia é 200 already_fetched sem nova requisição', function () {
    fakeFrankfurter(5.4321);

    $response = asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Primeira cotação do dia'])
        ->assertCreated()
        ->assertJsonPath('rate.source', 'frankfurter')
        ->assertJsonPath('rate.rate', 5.4321)
        ->assertJsonPath('rate.fetched_today', true)
        ->assertJsonPath('history_first.source', 'frankfurter')
        ->assertJsonPath('history_first.rate_brl_per_usd', 5.4321)
        ->assertJsonPath('history_first.recorded_by_name', null);

    expect($response->json('history_first.fetched_at'))->not->toBeNull()
        ->and(AiExchangeRate::query()->where('source', 'frankfurter')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.exchange_rate_fetched')->where('auditable_id', $response->json('history_first.id'))->exists())->toBeTrue();

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Tentativa repetida no dia'])
        ->assertOk()
        ->assertJsonPath('already_fetched', true)
        ->assertJsonPath('rate.source', 'frankfurter');

    expect(AiExchangeRate::query()->count())->toBe(1);
    Http::assertSentCount(1);

    // Manual gravada depois sobrepõe a automática de hoje.
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.75, 'reason' => 'Correção manual do dia'])
        ->assertCreated()
        ->assertJsonPath('rate.source', 'manual')
        ->assertJsonPath('rate.is_manual_override', true);
});

it('"buscar agora" com a API fora é 502 sem linha nova e mantém a cotação atual; lock ocupado é 409; sem motivo é 422', function () {
    config(['ai.exchange_rate.auto_fetch' => true]);
    Http::fake(['api.frankfurter.dev/*' => Http::response(null, 503)]);
    Log::spy();
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.10, 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Cotação de ontem']);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Tentativa com API fora'])
        ->assertStatus(502)
        ->assertJsonPath('rate.source', 'manual')
        ->assertJsonPath('rate.rate', 5.1);

    expect(AiExchangeRate::query()->where('source', 'frankfurter')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg) => $msg === 'ai.exchange_rate.fetch_failed')->once();

    $lock = Cache::lock('ai:exchange-rate:fetch:' . now()->toDateString(), 10);
    $lock->get();
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Tentativa com lock ocupado'])->assertStatus(409);
    $lock->release();

    // Trilha best-effort também cobre o 409: tentativa sem efeito continua auditada.
    expect(AuditLog::query()->where('event', 'manager.ai_pricing.exchange_rate_fetch_attempted')->latest('id')->value('new_values'))->toMatchArray(['status' => 'busy']);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    Http::assertSentCount(1);
});

it('sem a linha de auditoria a busca automática é desfeita (500, nenhuma cotação nova)', function () {
    fakeFrankfurter();
    $this->mock(AuditLogger::class)->shouldReceive('recordAdminAction');

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Sem trilha de auditoria'])->assertStatus(500);

    expect(AiExchangeRate::query()->count())->toBe(0)
        ->and(app(AiExchangeRateService::class)->current()['source'])->toBe('fallback');
});

it('salva markup e pisos com motivo, dentro da faixa, e audita; fora da faixa é 422 com o campo', function () {
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 6.5, 'minimum_charge_cents_default' => 20, 'reason' => 'Reajuste do custo de infra'])
        ->assertOk()
        ->assertJsonPath('settings.markup_multiplier', 6.5)
        ->assertJsonPath('settings.minimum_charge_cents_default', 20);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(6.5)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.settings_updated')->exists())->toBeTrue();

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 50, 'reason' => 'Tentativa fora da faixa'])
        ->assertStatus(422)
        ->assertJsonPath('field', 'markup_multiplier');

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 4])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(6.5);
});

it('grava cotação manual com autor, data e observação; histórico só cresce; fora da faixa é 422', function () {
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.25, 'note' => 'PTAX 22/10', 'reason' => 'Cotação do dia informada'])
        ->assertCreated()
        ->assertJsonPath('rate.rate', 5.25)
        ->assertJsonPath('rate.source', 'manual')
        ->assertJsonPath('history_first.recorded_by_name', $this->admin->name);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5.40, 'reason' => 'Correção da cotação'])
        ->assertCreated();

    expect(app(AiExchangeRateService::class)->current()['rate'])->toBe(5.4)
        ->and(AiExchangeRate::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'manager.ai_pricing.exchange_rate_recorded')->count())->toBe(2);

    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 55, 'reason' => 'Erro de digitação'])
        ->assertStatus(422)
        ->assertJsonPath('field', 'rate_brl_per_usd');

    expect(AiExchangeRate::query()->count())->toBe(2);
});

it('só dono ou admin financeiro do SaaS entra; financeiro sem ser dono e suporte não', function () {
    $owner = User::factory()->create();
    createEntityUser($this->saas, $owner, SaasRule::Financial->value, isOwner: true);
    asPricingUser($owner, SaasRule::Financial->value)->get(route('manager.ai-pricing.index'))->assertOk();

    $financial = User::factory()->create();
    createEntityUser($this->saas, $financial, SaasRule::Financial->value);
    asPricingUser($financial, SaasRule::Financial->value)->get(route('manager.ai-pricing.index'))->assertForbidden();
    asPricingUser($financial, SaasRule::Financial->value)->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 2, 'reason' => 'Tentativa sem permissão'])->assertForbidden();

    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);
    asPricingUser($support, SaasRule::Support->value)->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 5, 'reason' => 'Tentativa sem permissão'])->assertForbidden();
    asPricingUser($financial, SaasRule::Financial->value)->postJson(route('manager.ai-pricing.exchange-rates.fetch'), ['reason' => 'Tentativa sem permissão'])->assertForbidden();

    expect(AiExchangeRate::query()->count())->toBe(0);
});

it('sem a linha de auditoria nada entra em vigor: markup e cotação são desfeitos', function () {
    config(['ai.pricing.markup_multiplier' => 5.0]);
    // AuditLogger que "engole" a falha de escrita (comportamento real quando audit_logs está indisponível).
    // Durante a auditoria (ainda dentro da transação) o cache compartilhado NÃO pode conter o markup novo (Sentinela S3).
    $this->mock(AuditLogger::class)->shouldReceive('recordAdminAction')->andReturnUsing(function (): void {
        expect(Cache::has('subscription_setting:' . AiPricingSettings::KEY_MARKUP))->toBeFalse();
    });

    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_multiplier' => 9, 'reason' => 'Sem trilha de auditoria'])
        ->assertStatus(500);
    asPricingUser()->putJson(route('manager.ai-pricing.settings'), ['markup_by_workflow' => ['report_drafting' => 9], 'reason' => 'Sem trilha de auditoria'])
        ->assertStatus(500);
    asPricingUser()->postJson(route('manager.ai-pricing.exchange-rates.store'), ['rate_brl_per_usd' => 6.0, 'reason' => 'Sem trilha de auditoria'])
        ->assertStatus(500);

    expect(app(AiPricingSettings::class)->markupMultiplier())->toBe(5.0)
        ->and(app(AiPricingSettings::class)->markupByWorkflow())->toBe([])
        ->and(Cache::has('subscription_setting:' . AiPricingSettings::KEY_MARKUP_BY_WORKFLOW))->toBeFalse()
        ->and(AiExchangeRate::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'like', 'manager.ai_pricing.%')->count())->toBe(0);
});

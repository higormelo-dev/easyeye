<?php

declare(strict_types=1);

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiRun};
use App\Domains\AI\Services\{AiCreditWalletService, AiOrchestrator, AiPricingPolicyResolver, AiPricingService, AiRunExecutionService, AiSafetyService, EyeImageAttachmentService};
use App\DTOs\AI\{AiCreditEstimateData, AiPricingPolicyData, AiProviderResponseData, AiUsageData, AiWorkflowResultData};
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode, AiRunStatus};
use Illuminate\Support\Str;
use Tests\Support\Fakes\InMemoryRunRepository;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/*
 * Liquidação em CENTAVOS de BRL: o preço real (custo USD × câmbio × markup) é consumido até o limite da reserva; a sobra
 * volta; excedente não é cobrado (fica como nota). A política congelada na run tem precedência sobre a vigente.
 */

test('execution service consome e libera reserva no sucesso', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 10000);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);
    $policies     = Mockery::mock(AiPricingPolicyResolver::class);

    $responses = [
        new AiProviderResponseData(
            provider: AiProvider::OpenAI,
            model: 'gpt-fake-5',
            content: 'draft',
            usage: new AiUsageData(inputTokens: 100, outputTokens: 50, rawCostUsd: 0.05),
            latencyMs: 120,
        ),
    ];

    $orchestrator->shouldReceive('execute')
        ->once()
        ->andReturn(new AiWorkflowResultData(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            finalOutput: 'resultado final',
            providerCalls: $responses,
            safetyNotes: ['nota'],
        ));

    // A run não tem política congelada: usa a vigente, UMA vez.
    $policies->shouldReceive('current')->once()->andReturn(buildPolicy());

    $pricing->shouldReceive('calculateActualCharge')
        ->once()
        ->withArgs(fn (string $workflow, AiRunMode $mode, array $calls, AiPricingPolicyData $policy) => $policy->markupMultiplier === 5.0)
        ->andReturn(buildEstimate(rawCostUsd: 0.2, chargeCents: 6000));

    $wallet->shouldReceive('consumeReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 6000)
        ->andReturn(buildLedgerEntry());

    $wallet->shouldReceive('releaseReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 4000)
        ->andReturn(buildLedgerEntry());

    $service = buildExecutionService($repository, $orchestrator, $pricing, $policies, $wallet);
    $service->execute($run);

    expect($run->dispatch_audit['data_categories'] ?? null)->toContain('request_text');

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
    expect((int) $run->consumed_charge_cents)->toBe(6000);
    expect((float) $run->actual_cost_usd)->toBe(0.2);
    expect($run->final_output)->toBe('resultado final');
});

test('execution service usa a política congelada na run, não a vigente', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 10000, frozenPolicy: true);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);
    $policies     = Mockery::mock(AiPricingPolicyResolver::class);

    $orchestrator->shouldReceive('execute')->once()->andReturn(new AiWorkflowResultData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        finalOutput: 'ok',
        providerCalls: [],
        safetyNotes: [],
    ));

    $policies->shouldNotReceive('current');

    $pricing->shouldReceive('calculateActualCharge')
        ->once()
        ->withArgs(fn (string $workflow, AiRunMode $mode, array $calls, AiPricingPolicyData $policy) => $policy->exchangeRateBrlUsd === 4.0 && $policy->markupMultiplier === 3.0)
        ->andReturn(buildEstimate(rawCostUsd: 0.1, chargeCents: 1000));

    $wallet->shouldReceive('consumeReservation')->once()->withArgs(fn (...$args) => $args[1] === 1000)->andReturn(buildLedgerEntry());
    $wallet->shouldReceive('releaseReservation')->once()->withArgs(fn (...$args) => $args[1] === 9000)->andReturn(buildLedgerEntry());

    buildExecutionService($repository, $orchestrator, $pricing, $policies, $wallet)->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval)
        ->and((int) $run->consumed_charge_cents)->toBe(1000);
});

test('execution service libera reserva e marca failed quando ocorre erro', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 7000);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);
    $policies     = Mockery::mock(AiPricingPolicyResolver::class);

    $orchestrator->shouldReceive('execute')
        ->once()
        ->andThrow(new RuntimeException('falha de execução'));

    $wallet->shouldReceive('releaseReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 7000)
        ->andReturn(buildLedgerEntry());

    $service = buildExecutionService($repository, $orchestrator, $pricing, $policies, $wallet);

    expect(fn () => $service->execute($run))
        ->toThrow(RuntimeException::class, 'falha de execução');

    expect($run->status)->toBe(AiRunStatus::Failed);
    // O médico recebe mensagem GENÉRICA — o erro interno não vaza para a UI
    // (decisão da reformulação do assistente; o detalhe fica em log/metadata).
    expect((string) $run->error_message)->toContain('Não foi possível concluir a análise');
});

test('execution service quando actual > reserved adiciona safety note e não consome extra', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 5000);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);
    $policies     = Mockery::mock(AiPricingPolicyResolver::class);

    $orchestrator->shouldReceive('execute')
        ->once()
        ->andReturn(new AiWorkflowResultData(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            finalOutput: 'output',
            providerCalls: [],
            safetyNotes: ['nota base'],
        ));

    $policies->shouldReceive('current')->once()->andReturn(buildPolicy());

    $pricing->shouldReceive('calculateActualCharge')
        ->once()
        ->andReturn(buildEstimate(rawCostUsd: 1.0, chargeCents: 20000)); // muito maior que o reservado (5000)

    // Deve consumir apenas 5000 (o reservado), nunca mais que isso.
    $wallet->shouldReceive('consumeReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 5000)
        ->andReturn(buildLedgerEntry());

    // NÃO deve chamar release — não sobrou reserva pra liberar.
    $wallet->shouldNotReceive('releaseReservation');

    buildExecutionService($repository, $orchestrator, $pricing, $policies, $wallet)->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
    expect((int) $run->consumed_charge_cents)->toBe(5000);
    expect($run->safety_notes)->toContain('Custo real acima da reserva inicial; valor extra não cobrado automaticamente.');
});

test('execution service ignora runs em estado terminal', function () {
    $run        = buildRunForExecution(AiRunStatus::WaitingApproval, 3000);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);
    $policies     = Mockery::mock(AiPricingPolicyResolver::class);

    $orchestrator->shouldNotReceive('execute');
    $wallet->shouldNotReceive('consumeReservation');
    $wallet->shouldNotReceive('releaseReservation');

    buildExecutionService($repository, $orchestrator, $pricing, $policies, $wallet)->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
});

function buildExecutionService(InMemoryRunRepository $repository, AiOrchestrator $orchestrator, AiPricingService $pricing, AiPricingPolicyResolver $policies, AiCreditWalletService $wallet): AiRunExecutionService
{
    return new AiRunExecutionService($repository, $orchestrator, $pricing, $policies, $wallet, new AiSafetyService(), new EyeImageAttachmentService());
}

function buildPolicy(float $rate = 5.0, float $markup = 5.0): AiPricingPolicyData
{
    return new AiPricingPolicyData(
        exchangeRateBrlUsd: $rate,
        markupMultiplier: $markup,
        minimumChargeCentsDefault: 10,
        minimumChargeCentsByWorkflow: ['report_drafting' => 10],
        source: 'manual',
    );
}

function buildEstimate(float $rawCostUsd, int $chargeCents): AiCreditEstimateData
{
    return new AiCreditEstimateData(
        workflow: 'report_drafting',
        mode: AiRunMode::Validated,
        rawCostUsd: $rawCostUsd,
        grossChargeCents: $chargeCents,
        minimumChargeCents: 10,
        minimumApplied: false,
        chargeCents: $chargeCents,
        policy: buildPolicy(),
        breakdown: [],
    );
}

function buildRunForExecution(AiRunStatus $status, int $reservedChargeCents, bool $frozenPolicy = false): AiRun
{
    $run = new AiRun([
        'entity_id'             => (string) Str::uuid(),
        'workflow'              => 'report_drafting',
        'mode'                  => AiRunMode::Validated->value,
        'risk_level'            => AiRiskLevel::Medium->value,
        'status'                => $status->value,
        'reserved_charge_cents' => $reservedChargeCents,
        'consumed_charge_cents' => 0,
        'input_summary'         => [
            'user_prompt'   => 'Prompt clínico para apoio médico.',
            'system_prompt' => 'Apoio clínico, nunca decisão final.',
        ],
    ]);

    if ($frozenPolicy) {
        $run->forceFill([
            'exchange_rate_brl_usd' => '4.000000',
            'markup_multiplier'     => '3.000',
            'minimum_charge_cents'  => 10,
            'pricing_source'        => 'manual',
        ]);
    }

    $run->id = (string) Str::uuid();

    return $run;
}

function buildLedgerEntry(): AiCreditLedgerEntry
{
    $entry = new AiCreditLedgerEntry([
        'entity_id'           => (string) Str::uuid(),
        'type'                => 'reserve',
        'amount_cents'        => 0,
        'balance_after_cents' => 0,
        'unit'                => 'brl_cent',
    ]);
    $entry->id = (string) Str::uuid();

    return $entry;
}

<?php

declare(strict_types=1);

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Models\{AiCreditLedgerEntry, AiRun};
use App\Domains\AI\Services\{AiCreditWalletService, AiOrchestrator, AiPricingService, AiRunExecutionService, AiSafetyService, EyeImageAttachmentService};
use App\DTOs\AI\{AiCreditEstimateData, AiProviderResponseData, AiUsageData, AiWorkflowResultData};
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode, AiRunStatus};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('execution service consome e libera reserva no sucesso', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 100);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);

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

    $pricing->shouldReceive('calculateActualCredits')
        ->once()
        ->andReturn(new AiCreditEstimateData(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            rawCostUsd: 0.2,
            costUsdWithMargin: 0.4,
            marginMultiplier: 2.0,
            usdPerCredit: 0.01,
            creditsBeforeMinimum: 40,
            minimumCredits: 5,
            minimumApplied: false,
            normalizedCredits: 60,
            breakdown: [],
        ));

    $wallet->shouldReceive('consumeReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 60)
        ->andReturn(buildLedgerEntry());

    $wallet->shouldReceive('releaseReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 40)
        ->andReturn(buildLedgerEntry());

    $service = new AiRunExecutionService($repository, $orchestrator, $pricing, $wallet, new AiSafetyService(), new EyeImageAttachmentService());
    $service->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
    expect($run->consumed_credits)->toBe(60);
    expect($run->final_output)->toBe('resultado final');
});

test('execution service libera reserva e marca failed quando ocorre erro', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 70);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);

    $orchestrator->shouldReceive('execute')
        ->once()
        ->andThrow(new RuntimeException('falha de execução'));

    $wallet->shouldReceive('releaseReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 70)
        ->andReturn(buildLedgerEntry());

    $service = new AiRunExecutionService($repository, $orchestrator, $pricing, $wallet, new AiSafetyService(), new EyeImageAttachmentService());

    expect(fn () => $service->execute($run))
        ->toThrow(RuntimeException::class, 'falha de execução');

    expect($run->status)->toBe(AiRunStatus::Failed);
    // O médico recebe mensagem GENÉRICA — o erro interno não vaza para a UI
    // (decisão da reformulação do assistente; o detalhe fica em log/metadata).
    expect((string) $run->error_message)->toContain('Não foi possível concluir a análise');
});

test('execution service quando actual > reserved adiciona safety note e não consome extra', function () {
    $run        = buildRunForExecution(AiRunStatus::Reserved, 50);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);

    $orchestrator->shouldReceive('execute')
        ->once()
        ->andReturn(new AiWorkflowResultData(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            finalOutput: 'output',
            providerCalls: [],
            safetyNotes: ['nota base'],
        ));

    $pricing->shouldReceive('calculateActualCredits')
        ->once()
        ->andReturn(new AiCreditEstimateData(
            workflow: 'report_drafting',
            mode: AiRunMode::Validated,
            rawCostUsd: 1.0,
            costUsdWithMargin: 2.0,
            marginMultiplier: 2.0,
            usdPerCredit: 0.01,
            creditsBeforeMinimum: 200,
            minimumCredits: 5,
            minimumApplied: false,
            normalizedCredits: 200, // muito maior que reservado (50)
            breakdown: [],
        ));

    // Deve consumir apenas 50 (o reservado), nunca mais que isso.
    $wallet->shouldReceive('consumeReservation')
        ->once()
        ->withArgs(fn (...$args) => $args[1] === 50)
        ->andReturn(buildLedgerEntry());

    // NÃO deve chamar release — não sobrou reserva pra liberar.
    $wallet->shouldNotReceive('releaseReservation');

    $service = new AiRunExecutionService($repository, $orchestrator, $pricing, $wallet, new AiSafetyService(), new EyeImageAttachmentService());
    $service->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
    expect($run->consumed_credits)->toBe(50);
    expect($run->safety_notes)->toContain('Custo real acima da reserva inicial; crédito extra não cobrado automaticamente.');
});

test('execution service ignora runs em estado terminal', function () {
    $run        = buildRunForExecution(AiRunStatus::WaitingApproval, 30);
    $repository = new InMemoryRunRepository();

    $orchestrator = Mockery::mock(AiOrchestrator::class);
    $pricing      = Mockery::mock(AiPricingService::class);
    $wallet       = Mockery::mock(AiCreditWalletService::class);

    $orchestrator->shouldNotReceive('execute');
    $wallet->shouldNotReceive('consumeReservation');
    $wallet->shouldNotReceive('releaseReservation');

    $service = new AiRunExecutionService($repository, $orchestrator, $pricing, $wallet, new AiSafetyService(), new EyeImageAttachmentService());
    $service->execute($run);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval);
});

function buildRunForExecution(AiRunStatus $status, int $reservedCredits): AiRun
{
    $run = new AiRun([
        'entity_id'        => (string) Str::uuid(),
        'workflow'         => 'report_drafting',
        'mode'             => AiRunMode::Validated->value,
        'risk_level'       => AiRiskLevel::Medium->value,
        'status'           => $status->value,
        'reserved_credits' => $reservedCredits,
        'consumed_credits' => 0,
        'input_summary'    => [
            'user_prompt'   => 'Prompt clínico para apoio médico.',
            'system_prompt' => 'Apoio clínico, nunca decisão final.',
        ],
    ]);

    $run->id = (string) Str::uuid();

    return $run;
}

class InMemoryRunRepository implements AiRunRepositoryInterface
{
    public function find(string $id): ?AiRun
    {
        return null;
    }

    public function markRunning(AiRun $run): void
    {
        $run->forceFill([
            'status'        => AiRunStatus::Running,
            'error_message' => null,
        ]);
    }

    public function markWaitingApproval(
        AiRun $run,
        string $finalOutput,
        array $safetyNotes,
        int $consumedCredits,
        ?string $errorMessage = null,
    ): void {
        $run->forceFill([
            'status'           => AiRunStatus::WaitingApproval,
            'final_output'     => $finalOutput,
            'safety_notes'     => $safetyNotes,
            'consumed_credits' => $consumedCredits,
            'error_message'    => $errorMessage,
        ]);
    }

    public function markFailed(AiRun $run, string $errorMessage): void
    {
        $run->forceFill([
            'status'        => AiRunStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    public function markCancelled(AiRun $run): void
    {
        $run->forceFill([
            'status'       => AiRunStatus::Cancelled,
            'cancelled_at' => $run->cancelled_at ?? now(),
        ]);
    }
}

function buildLedgerEntry(): AiCreditLedgerEntry
{
    $entry = new AiCreditLedgerEntry([
        'entity_id'     => (string) Str::uuid(),
        'type'          => 'reserve',
        'amount'        => 0,
        'balance_after' => 0,
    ]);
    $entry->id = (string) Str::uuid();

    return $entry;
}

<?php

declare(strict_types=1);

use App\Domains\AI\Contracts\AiRunRepositoryInterface;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiRunExecutionService;
use App\Jobs\AI\RunAiWorkflowJob;
use App\Enums\AI\AiRiskLevel;
use App\Enums\AI\AiRunMode;
use App\Enums\AI\AiRunStatus;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('job executa workflow quando run existe', function () {
    $run = new AiRun([
        'entity_id' => (string) Str::uuid(),
        'workflow' => 'report_drafting',
        'mode' => AiRunMode::Validated->value,
        'risk_level' => AiRiskLevel::Low->value,
        'status' => AiRunStatus::Reserved->value,
        'reserved_credits' => 50,
    ]);
    $run->id = (string) Str::uuid();

    $repository = \Mockery::mock(AiRunRepositoryInterface::class);
    $executor = \Mockery::mock(AiRunExecutionService::class);

    $repository->shouldReceive('find')
        ->once()
        ->with((string) $run->id)
        ->andReturn($run);

    $executor->shouldReceive('execute')
        ->once()
        ->with($run);

    $job = new RunAiWorkflowJob((string) $run->id);
    $job->handle($repository, $executor);
});

test('job não executa quando run não existe', function () {
    $repository = \Mockery::mock(AiRunRepositoryInterface::class);
    $executor = \Mockery::mock(AiRunExecutionService::class);

    $repository->shouldReceive('find')
        ->once()
        ->with('run-inexistente')
        ->andReturn(null);

    $executor->shouldNotReceive('execute');

    $job = new RunAiWorkflowJob('run-inexistente');
    $job->handle($repository, $executor);
});

test('failed() libera reserva e marca run como Failed', function () {
    $run = new AiRun([
        'entity_id' => (string) Str::uuid(),
        'workflow' => 'report_drafting',
        'mode' => AiRunMode::Validated->value,
        'risk_level' => AiRiskLevel::Low->value,
        'status' => AiRunStatus::Running->value,
        'reserved_credits' => 90,
    ]);
    $run->id = (string) Str::uuid();

    $repository = \Mockery::mock(AiRunRepositoryInterface::class);
    $executor = \Mockery::mock(AiRunExecutionService::class);

    $repository->shouldReceive('find')
        ->once()
        ->with((string) $run->id)
        ->andReturn($run);

    $executor->shouldReceive('compensateFailedRun')
        ->once()
        ->withArgs(fn ($r, $reason) => $r === $run && str_contains($reason, 'queue worker timeout'));

    // Resolve via container — Job::failed() usa app() para resolver dependências.
    app()->instance(AiRunRepositoryInterface::class, $repository);
    app()->instance(AiRunExecutionService::class, $executor);

    $job = new RunAiWorkflowJob((string) $run->id);
    $job->failed(new RuntimeException('queue worker timeout'));
});

test('failed() é silencioso quando run não existe', function () {
    $repository = \Mockery::mock(AiRunRepositoryInterface::class);
    $executor = \Mockery::mock(AiRunExecutionService::class);

    $repository->shouldReceive('find')
        ->once()
        ->with('run-perdido')
        ->andReturn(null);

    $executor->shouldNotReceive('compensateFailedRun');

    app()->instance(AiRunRepositoryInterface::class, $repository);
    app()->instance(AiRunExecutionService::class, $executor);

    $job = new RunAiWorkflowJob('run-perdido');
    $job->failed(new RuntimeException('erro qualquer'));
});

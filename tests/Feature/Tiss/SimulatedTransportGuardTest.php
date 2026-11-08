<?php

declare(strict_types=1);

/**
 * Trava do envio TISS em produção com o transporte simulado. O `mock` grava um protocolo "Aceito" que a
 * operadora nunca viu: a clínica acharia que faturou. Em produção o envio só vale com o driver `http`
 * (ou com TISS_ALLOW_SIMULATED_IN_PRODUCTION=true, para demonstração). A trava é só de configuração,
 * então roda antes de qualquer escrita (nenhuma transmissão, nenhum protocolo).
 */

use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Exceptions\TissSimulatedTransportException;
use App\Domains\Tiss\Jobs\SendTissBatchJob;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissGuide, TissOperator, TissTransmission};
use App\Domains\Tiss\Services\SendTissBatchService;
use App\Domains\Tiss\Services\Transport\{SimulatedTransportGuard, TissTransportContract};
use App\Domains\Tiss\Surgical\SendSurgicalGuideService;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sgSetup(string $env, string $driver, bool $allow = false): void
{
    app()->detectEnvironment(fn () => $env);
    config(['tiss.transport.driver' => $driver, 'tiss.transport.allow_simulated_in_production' => $allow]);
}

function sgBatch(): TissBatch
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    TissEntityOperatorCredential::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'environment' => (string) config('tiss.transport.environment', 'production'),
        'active'      => true,
    ]);

    return TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);
}

/** Transporte que conta as chamadas: "zero" é o que a trava promete. */
function sgTransport(): object
{
    $transport = new class() implements TissTransportContract {
        public int $calls = 0;

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->calls++;

            return ['ok' => true, 'status_code' => 200, 'response_body' => '<ansTISS/>', 'protocol_number' => 'PRT-1', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1];
        }
    };

    app()->instance(TissTransportContract::class, $transport);

    return $transport;
}

it('só bloqueia produção com transporte diferente de http e sem a liberação explícita', function (string $env, string $driver, bool $allow, bool $blocked) {
    sgSetup($env, $driver, $allow);

    expect(SimulatedTransportGuard::blocked())->toBe($blocked);

    $blocked
        ? expect(fn () => SimulatedTransportGuard::assertAllowed())->toThrow(TissSimulatedTransportException::class, __('tiss_send.simulated_blocked'))
        : SimulatedTransportGuard::assertAllowed();
})->with([
    'produção com mock'                => ['production', 'mock', false, true],
    'produção com driver vazio'        => ['production', '', false, true],
    'produção com mock e demonstração' => ['production', 'mock', true, false],
    'produção com http'                => ['production', 'http', false, false],
    'ambiente local com mock'          => ['local', 'mock', false, false],
    'ambiente de testes com mock'      => ['testing', 'mock', false, false],
]);

it('lote: o envio em produção simulada falha antes de reivindicar qualquer coisa', function () {
    sgSetup('production', 'mock');
    $batch     = sgBatch();
    $transport = sgTransport();

    expect(fn () => app(SendTissBatchService::class)->send($batch))
        ->toThrow(TissSimulatedTransportException::class, __('tiss_send.simulated_blocked'));

    expect($transport->calls)->toBe(0)
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::XmlReady);
});

it('lote: a mesma conta liberada para demonstração passa pela trava (segue para as regras normais do envio)', function () {
    sgSetup('production', 'mock', true);
    $batch = sgBatch();

    // Sem XML válido o envio ainda falha, mas por outro motivo: a trava de transporte não é a causa.
    try {
        app(SendTissBatchService::class)->send($batch);
    } catch (Throwable $e) {
        expect($e)->not->toBeInstanceOf(TissSimulatedTransportException::class);
    }
});

it('guia cirúrgica avulsa: produção simulada devolve a regra de negócio 409 com o aviso', function () {
    sgSetup('production', 'mock');

    try {
        app(SendSurgicalGuideService::class)->send(new TissGuide());
        $this->fail('esperava SurgeryRuleException');
    } catch (SurgeryRuleException $e) {
        expect($e->status)->toBe(409)
            ->and($e->getMessage())->toBe(__('surgery_tiss.errors.transport_simulated'));
    }
});

it('job de envio: falha já, sem retentativa, e leva o lote a erro com o motivo', function () {
    sgSetup('production', 'mock');
    $batch     = sgBatch();
    $transport = sgTransport();

    SendTissBatchJob::dispatch((string) $batch->id, (string) $batch->entity_id);

    $batch->refresh();

    expect($batch->status)->toBe(TissBatchStatus::Error)
        ->and((string) $batch->last_error)->toContain('modo simulação')
        ->and($transport->calls)->toBe(0)
        ->and(TissTransmission::query()->count())->toBe(0);
});

it('mensagens da trava existem nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        expect(trim((string) __('tiss_send.simulated_blocked', [], $locale)))->not->toBe('tiss_send.simulated_blocked')
            ->and(trim((string) __('surgery_tiss.errors.transport_simulated', [], $locale)))->not->toBe('surgery_tiss.errors.transport_simulated');
    }
});

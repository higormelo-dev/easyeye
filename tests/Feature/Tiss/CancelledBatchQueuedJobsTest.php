<?php

declare(strict_types=1);

/**
 * Lote TISS cancelado com a geração do XML e o envio JÁ NA FILA — achado via
 * revisão adversarial Codex (23ª rodada). Com os workers das filas tiss-xml/
 * tiss-send ligados, esses jobs rodam mesmo depois do cancelamento. A geração só
 * protegia os lotes que já saíram (enviando...pago), então regerava o XML das
 * guias canceladas ainda anexadas, devolvia o lote a "XML pronto" e as guias a
 * "XML gerado" — e o envio seguinte, sem mais nenhum sinal do cancelamento,
 * transmitia o que o usuário cancelou.
 *
 * Agora generateBatch() recusa o lote cancelado sob a trava, antes de qualquer
 * escrita, e os dois jobs tratam o cancelamento como "nada a fazer" (sem retentativas,
 * sem relatório de falha).
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus};
use App\Domains\Tiss\Exceptions\TissBatchCancelledException;
use App\Domains\Tiss\Jobs\{GenerateTissBatchXmlJob, SendTissBatchJob};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissStatusHistory, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService, TissWorkflowService};
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use App\Models\Entity;
use App\Services\Financial\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Storage};

uses(RefreshDatabase::class);

/** Lote TISS de verdade (CNES + CRM, guia real), fechado e ainda SEM XML. */
function qcjClosedBatch(): TissBatch
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'cnes' => '1234567', 'state' => 'SP']);
    actingAsFinancialEntityUser($entity);

    $schedule = createBillableSchedule($entity);
    $schedule->doctor->update(['record' => '123456']);

    $billing = app(BillingService::class)->createBatch([
        'covenant_id'           => $schedule->covenant_id,
        'date_from'             => now()->subDay()->toDateString(),
        'date_until'            => now()->toDateString(),
        'unit_price'            => 150,
        'tuss_code'             => '10101012',
        'procedure_description' => 'CONSULTA OFTALMOLOGICA',
        'clinical_indication'   => 'H52.1',
    ]);

    return app(TissWorkflowService::class)->closeBatch(TissBatch::query()->findOrFail($billing->tiss_batch_id))->fresh();
}

/** Transporte que conta as requisições — "zero chamadas" é o que o teste mede. */
function qcjTransport(): object
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

/** @return list<string> */
function qcjGuideStatuses(TissBatch $batch): array
{
    return $batch->guides()->pluck('tiss_guides.status')->map(fn ($status) => $status->value)->unique()->values()->all();
}

it('[CANCELADO] geração e envio já enfileirados + lote cancelado: executar os dois jobs não regera o XML, não revive o lote nem as guias e não manda nada à operadora', function () {
    Queue::fake();
    $transport = qcjTransport();
    $batch     = qcjClosedBatch();
    $workflow  = app(TissWorkflowService::class);

    $workflow->generateXmlInQueue($batch);
    $workflow->sendBatchInQueue($batch);

    Queue::assertPushed(GenerateTissBatchXmlJob::class);
    Queue::assertPushed(SendTissBatchJob::class);

    // O usuário cancela DEPOIS de enfileirar.
    $workflow->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled)
        ->and(qcjGuideStatuses($batch))->toBe([TissGuideStatus::Cancelled->value]);

    $historyBefore = TissStatusHistory::query()->count();

    // Os workers pegam os jobs, na ordem em que foram enfileirados.
    Queue::pushed(GenerateTissBatchXmlJob::class)->first()->handle(app(GenerateTissXmlService::class));
    Queue::pushed(SendTissBatchJob::class)->first()->handle(app(SendTissBatchService::class));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled)
        ->and($batch->fresh()->xml_document_id)->toBeNull()
        ->and(qcjGuideStatuses($batch))->toBe([TissGuideStatus::Cancelled->value])
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and(TissStatusHistory::query()->count())->toBe($historyBefore) // nenhuma transição gravada
        ->and($transport->calls)->toBe(0);
});

it('o envio na ORDEM INVERSA (o job de envio roda antes do de geração) também não transmite nada nem gera XML', function () {
    Queue::fake();
    $transport = qcjTransport();
    $batch     = qcjClosedBatch();
    $workflow  = app(TissWorkflowService::class);

    $workflow->generateXmlInQueue($batch);
    $workflow->sendBatchInQueue($batch);
    $workflow->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    Queue::pushed(SendTissBatchJob::class)->first()->handle(app(SendTissBatchService::class));
    Queue::pushed(GenerateTissBatchXmlJob::class)->first()->handle(app(GenerateTissXmlService::class));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and($transport->calls)->toBe(0);
});

it('generateBatch() recusa o lote cancelado SOB A TRAVA, antes de qualquer escrita: nada de XML, status, guias, histórico nem arquivo novos', function () {
    Storage::fake();
    $batch = qcjClosedBatch();
    app(TissWorkflowService::class)->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    $historyBefore = TissStatusHistory::query()->count();

    expect(fn () => app(GenerateTissXmlService::class)->generateBatch($batch->fresh()))
        ->toThrow(TissBatchCancelledException::class, __('tiss_send.batch_cancelled_xml'));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled)
        ->and(qcjGuideStatuses($batch))->toBe([TissGuideStatus::Cancelled->value])
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and(TissStatusHistory::query()->count())->toBe($historyBefore)
        // O rollback da transação não desfaz arquivo: a recusa tem que vir ANTES de gravar o XML no disco.
        ->and(Storage::allFiles())->toBe([]);
});

it('o cancelamento que chega com o lote JÁ na memória do worker (instância velha, status ainda "fechado") também é recusado: a trava relê o status', function () {
    $batch = qcjClosedBatch();
    $stale = $batch->fresh(); // o que o worker leu antes do cancelamento

    app(TissWorkflowService::class)->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    expect($stale->status)->toBe(TissBatchStatus::Closed)
        ->and(fn () => app(GenerateTissXmlService::class)->generateBatch($stale))->toThrow(TissBatchCancelledException::class);

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled);
});

it('envio síncrono de lote cancelado sem XML: a mensagem de lote cancelado sobe e nada é gerado nem transmitido (antes a geração revivia o lote e o envio saía)', function () {
    $transport = qcjTransport();
    $batch     = qcjClosedBatch();
    app(TissWorkflowService::class)->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(TissBatchCancelledException::class);

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled)
        ->and(TissXmlDocument::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and($transport->calls)->toBe(0);
});

it('lote cancelado depois de ter XML pronto: o envio recusa na reivindicação (mesma exceção) e não transmite', function () {
    $transport = qcjTransport();
    $batch     = qcjClosedBatch();

    app(GenerateTissXmlService::class)->generateBatch($batch->fresh());
    app(TissWorkflowService::class)->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(TissBatchCancelledException::class, __('tiss_send.batch_cancelled'));

    expect($transport->calls)->toBe(0)
        ->and(TissTransmission::query()->count())->toBe(0);
});

it('a exceção de cancelamento que escapa do serviço (cancelamento entre a leitura do job e a trava) é engolida pelos jobs: sem retentativa, sem falha', function () {
    $batch = qcjClosedBatch();

    $generation = Mockery::mock(GenerateTissXmlService::class);
    $generation->shouldReceive('generateBatch')->once()->andThrow(new TissBatchCancelledException('cancelado'));

    $sending = Mockery::mock(SendTissBatchService::class);
    $sending->shouldReceive('send')->once()->andThrow(new TissBatchCancelledException('cancelado'));

    // Lote ainda "fechado" quando o job lê (a corrida): o serviço é que acusa o cancelamento.
    (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->handle($generation);
    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->handle($sending);

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Closed);
});

it('os jobs nem chamam o serviço quando o lote já está cancelado ao lerem (pré-checagem barata, sem carregar relações nem travar nada)', function () {
    $batch = qcjClosedBatch();
    app(TissWorkflowService::class)->cancelBatch($batch->fresh(), 'cancelado pelo usuário');

    $generation = Mockery::mock(GenerateTissXmlService::class);
    $generation->shouldNotReceive('generateBatch');

    $sending = Mockery::mock(SendTissBatchService::class);
    $sending->shouldNotReceive('send');

    (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->handle($generation);
    (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->handle($sending);

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled);
});

it('lote que NÃO foi cancelado segue o fluxo normal: o job de geração gera o XML e deixa o lote pronto', function () {
    $batch = qcjClosedBatch();

    (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->handle(app(GenerateTissXmlService::class));

    expect($batch->fresh()->status)->toBe(TissBatchStatus::XmlReady)
        ->and($batch->fresh()->xml_document_id)->not->toBeNull();
});

it('outro erro do serviço NÃO é engolido pelos jobs: continua subindo para a fila retentar', function () {
    $batch = qcjClosedBatch();

    $generation = Mockery::mock(GenerateTissXmlService::class);
    $generation->shouldReceive('generateBatch')->once()->andThrow(new RuntimeException('falha de verdade'));

    $sending = Mockery::mock(SendTissBatchService::class);
    $sending->shouldReceive('send')->once()->andThrow(new RuntimeException('falha de verdade no envio'));

    expect(fn () => (new GenerateTissBatchXmlJob((string) $batch->id, (string) $batch->entity_id))->handle($generation))
        ->toThrow(RuntimeException::class, 'falha de verdade')
        ->and(fn () => (new SendTissBatchJob((string) $batch->id, (string) $batch->entity_id))->handle($sending))
        ->toThrow(RuntimeException::class, 'falha de verdade no envio');
});

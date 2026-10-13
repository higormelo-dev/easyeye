<?php

declare(strict_types=1);

/**
 * SendTissBatchService::send × geração do XML por OUTRA requisição — achado via
 * revisão adversarial Codex (14ª rodada).
 *
 * send() escolhia o XML do lote (A) e só depois, em outra transação, criava a
 * transmissão — sem reler qual era o XML do lote naquele instante. Outra
 * requisição podia gerar o XML B nesse intervalo (A ainda não tinha
 * transmissão, então a geração não o reaproveita) e enviar B; send() seguia
 * com A. Hashes diferentes (horário no cabeçalho) = chaves de idempotência
 * diferentes: nem o lease nem a unicidade barravam, e a operadora recebia o
 * lote duas vezes, com o lote apontando para B mesmo tendo A saído.
 *
 * Agora a transmissão é reivindicada com a linha do lote travada (a MESMA trava
 * da geração do XML) e o XML escolhido é conferido contra o do lote: trocado,
 * a escolha é refeita (adota o atual); lote cancelado nesse intervalo não sai.
 *
 * O "intervalo" é reproduzido de forma determinística: um gancho de DB::listen
 * dispara a outra requisição logo depois de send() reservar a chave do XML A
 * (INSERT em tiss_idempotency_keys) — escolhido, ainda sem transmissão. A
 * versão com duas conexões PostgreSQL de verdade está em tests/Integration.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, SendTissBatchService, TissWorkflowService};
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use App\Models\Entity;
use App\Services\Financial\BillingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\DB;

/** Transporte que registra cada requisição (chave + XML) e sempre confirma. */
function stdrTransport(): object
{
    $transport = new class() implements TissTransportContract {
        /** @var list<array{key: string, xml: string}> */
        public array $requests = [];

        public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
        {
            $this->requests[] = ['key' => $idempotencyKey, 'xml' => $xml];

            return [
                'ok'               => true,
                'status_code'      => 200,
                'response_body'    => '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>PRT-1</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>',
                'protocol_number'  => 'PRT-1',
                'protocol_status'  => 'accepted',
                'error_code'       => null,
                'error_message'    => null,
                'response_time_ms' => 12,
            ];
        }
    };

    app()->instance(TissTransportContract::class, $transport);

    return $transport;
}

/** Lote TISS fechado, com uma guia, XML A já gerado (válido: CNES + CRM) e ainda sem transmissão. */
function stdrBatchWithDocumentA(): TissBatch
{
    Carbon::setTestNow('2026-10-12 12:00:00');

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

    $batch = app(TissWorkflowService::class)->closeBatch(TissBatch::query()->findOrFail($billing->tiss_batch_id));

    app(GenerateTissXmlService::class)->generateBatch($batch->fresh());

    return $batch->fresh();
}

/**
 * Dispara $otherRequest uma vez a cada INSERT da chave de idempotência — o
 * instante em que send() já escolheu o XML e ainda não reivindicou a
 * transmissão. $max limita quantas vezes (padrão: só a primeira).
 */
function stdrBetweenSelectionAndClaim(callable $otherRequest, int $max = 1): void
{
    $fired   = 0;
    $running = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, &$running, $max, $otherRequest): void {
        if ($running || $fired >= $max || ! str_starts_with(strtolower($query->sql), 'insert into "tiss_idempotency_keys"')) {
            return;
        }

        $running = true;
        $fired++;

        try {
            $otherRequest();
        } finally {
            $running = false;
        }
    });
}

function stdrDocuments(TissBatch $batch): int
{
    return TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count();
}

it('[CORRIDA] outra requisição gera E ENVIA o XML B depois de send() escolher o A: send() adota o B e a operadora recebe UMA mensagem só', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();
    $docA      = $batch->xmlDocument;

    // A "outra requisição": regera (B) e envia (B inteiro: escolha, transmissão, confirmação).
    stdrBetweenSelectionAndClaim(function () use ($batch): void {
        test()->travel(15)->seconds(); // horário novo no cabeçalho = outro hash = outra chave

        app(GenerateTissXmlService::class)->generateBatch($batch->fresh());
        app(SendTissBatchService::class)->send($batch->fresh());
    });

    $result = app(SendTissBatchService::class)->send($batch->fresh());

    $current = $batch->fresh();

    expect($transport->requests)->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($transport->requests[0]['xml'])->toBe(TissXmlDocument::query()->findOrFail($current->xml_document_id)->xml_content)
        ->and($current->xml_document_id)->not->toBe($docA->id)
        ->and(TissTransmission::query()->sole()->xml_document_id)->toBe($current->xml_document_id)
        ->and($result->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($current->status)->toBe(TissBatchStatus::Sent);
});

it('[CORRIDA] outra requisição só GERA o XML B: send() larga o A, adota o B e transmite ele — o lote aponta para o que saiu', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();
    $docA      = $batch->xmlDocument;

    stdrBetweenSelectionAndClaim(function () use ($batch): void {
        test()->travel(15)->seconds();

        app(GenerateTissXmlService::class)->generateBatch($batch->fresh());
    });

    app(SendTissBatchService::class)->send($batch->fresh());

    $current      = $batch->fresh();
    $transmission = TissTransmission::query()->sole();

    expect($transport->requests)->toHaveCount(1)
        ->and($current->xml_document_id)->not->toBe($docA->id)
        ->and($transmission->xml_document_id)->toBe($current->xml_document_id)
        ->and($transport->requests[0]['xml'])->toBe(TissXmlDocument::query()->findOrFail($current->xml_document_id)->xml_content)
        ->and($transport->requests[0]['xml'])->not->toBe($docA->xml_content);

    // Quem tinha escolhido o B (a outra requisição) cai na transmissão que já existe.
    app(SendTissBatchService::class)->send($batch->fresh());

    expect($transport->requests)->toHaveCount(1)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('[CORRIDA] o XML continua trocando a cada tentativa: desiste após 3 escolhas com a mensagem traduzida — nada é transmitido', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();

    // A cada escolha nova, outra requisição já regerou (5 vezes é mais que o limite de tentativas).
    stdrBetweenSelectionAndClaim(function () use ($batch): void {
        test()->travel(15)->seconds();

        app(GenerateTissXmlService::class)->generateBatch($batch->fresh());
    }, max: 5);

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, __('tiss_send.xml_changed_during_send'));

    expect($transport->requests)->toBe([])
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->not->toBe(TissBatchStatus::Sending);
});

it('[CORRIDA] lote cancelado entre a escolha do XML e a reivindicação não é transmitido (nem volta de "cancelado" para "enviando")', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();

    stdrBetweenSelectionAndClaim(function () use ($batch): void {
        // CancelTissBatchAction comitou: o lote ainda não tinha saído, então pôde cancelar.
        TissBatch::query()->whereKey($batch->id)->update(['status' => TissBatchStatus::Cancelled->value]);
    });

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, __('tiss_send.batch_cancelled'));

    expect($transport->requests)->toBe([])
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Cancelled);
});

it('sem corrida nenhuma o envio continua igual: uma requisição, uma transmissão, o XML do lote', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();
    $docA      = $batch->xmlDocument;

    app(SendTissBatchService::class)->send($batch->fresh());

    expect($transport->requests)->toHaveCount(1)
        ->and($transport->requests[0]['xml'])->toBe($docA->xml_content)
        ->and(TissTransmission::query()->sole()->xml_document_id)->toBe($docA->id)
        ->and($batch->fresh()->xml_document_id)->toBe($docA->id)
        ->and(stdrDocuments($batch))->toBe(1);
});

it('[ORDEM DE LOCKS] a reivindicação trava a linha do lote ANTES de ler a transmissão e de gravar o status/INSERT — e o fechamento também', function () {
    stdrTransport();
    $batch = stdrBatchWithDocumentA();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(SendTissBatchService::class)->send($batch->fresh());

    $find = static fn (callable $match): int|false => collect($queries)->search($match);

    $claimLock   = $find(fn (string $sql) => str_contains($sql, 'from "tiss_batches"') && str_contains($sql, 'for update'));
    $transmRead  = $find(fn (string $sql) => str_contains($sql, 'from "tiss_transmissions"') && str_contains($sql, 'for update'));
    $batchSend   = $find(fn (string $sql) => str_starts_with($sql, 'update "tiss_batches"'));
    $insertTrans = $find(fn (string $sql) => str_starts_with($sql, 'insert into "tiss_transmissions"'));

    expect($claimLock)->toBeInt()
        ->and($transmRead)->toBeInt()
        ->and($claimLock)->toBeLessThan($transmRead)
        ->and($claimLock)->toBeLessThan($batchSend)
        ->and($claimLock)->toBeLessThan($insertTrans);

    // Fechamento (resposta da operadora gravada): a linha do lote de novo, antes de atualizar a transmissão.
    $finalLock    = collect($queries)->keys()->first(fn (int $i) => $i > $insertTrans && str_contains($queries[$i], 'from "tiss_batches"') && str_contains($queries[$i], 'for update'));
    $finalizeSend = collect($queries)->keys()->first(fn (int $i) => $i > $insertTrans && str_starts_with($queries[$i], 'update "tiss_transmissions"'));

    expect($finalLock)->toBeInt()
        ->and($finalizeSend)->toBeInt()
        ->and($finalLock)->toBeLessThan($finalizeSend);
});

it('[ATOMICIDADE] conflito de unicidade sem linha correspondente depois do rollback: falha sem enviar e sem deixar o lote "enviando"', function () {
    $transport = stdrTransport();
    $batch     = stdrBatchWithDocumentA();

    // Alguém grava a MESMA chave de idempotência dentro da reivindicação, antes do INSERT desta
    // requisição: o INSERT bate na unicidade e a transação inteira (inclusive o lote "enviando") é desfeita.
    $once = false;
    TissTransmission::creating(function (TissTransmission $transmission) use (&$once): void {
        if ($once) {
            return;
        }

        $once = true;

        DB::table('tiss_transmissions')->insert([
            'id'              => (string) Str::uuid(),
            'entity_id'       => $transmission->entity_id,
            'operator_id'     => $transmission->operator_id,
            'batch_id'        => $transmission->batch_id,
            'xml_document_id' => $transmission->xml_document_id,
            'idempotency_key' => $transmission->idempotency_key,
            'status'          => TissTransmissionStatus::Acknowledged->value,
            'queue'           => 'tiss-send',
            'attempt'         => 1,
            'max_attempts'    => 5,
            'requested_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    });

    expect(fn () => app(SendTissBatchService::class)->send($batch->fresh()))
        ->toThrow(RuntimeException::class, 'Conflito de idempotência TISS');

    expect($transport->requests)->toBe([])
        ->and(TissTransmission::query()->count())->toBe(0)
        ->and($batch->fresh()->status)->not->toBe(TissBatchStatus::Sending);
});

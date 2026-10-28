<?php

declare(strict_types=1);

/**
 * GenerateTissXmlService::generateBatch × lote que já foi (ou pode ter ido) à
 * operadora — achado via revisão adversarial Codex (13ª rodada).
 *
 * O builder grava o horário corrente no cabeçalho e no hash do epílogo, e a
 * chave de idempotência do envio nasce do hash do arquivo: regerar o MESMO
 * lote um segundo depois dava outra chave. Além disso, regerar um lote
 * enviado o empurrava de "enviado" de volta para "XML pronto" (e as guias
 * idem). Agora:
 *  - lote enviando/enviado/processado/pago: devolve o XML enviado, intacto;
 *  - documento já oferecido ao transporte + conteúdo igual: devolve o mesmo;
 *  - documento nunca transmitido, ou conteúdo diferente: gera um novo.
 *
 * Cobre o serviço direto (billing: BillingTissRetryIdempotencyTest).
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissTransmissionStatus, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{GenerateTissXmlService, TissWorkflowService};
use App\Models\Entity;
use App\Services\Financial\BillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lote TISS fechado, com uma guia, pronto para gerar XML válido (CNES + CRM). */
function gtxBatch(): TissBatch
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

    $tiss = TissBatch::query()->findOrFail($billing->tiss_batch_id);

    return app(TissWorkflowService::class)->closeBatch($tiss);
}

function gtxGenerate(TissBatch $batch): TissXmlDocument
{
    return app(GenerateTissXmlService::class)->generateBatch($batch->fresh());
}

function gtxTransmit(TissBatch $batch, TissXmlDocument $document, TissTransmissionStatus $status = TissTransmissionStatus::Failed): TissTransmission
{
    return TissTransmission::query()->create([
        'entity_id'       => $batch->entity_id,
        'operator_id'     => $batch->operator_id,
        'batch_id'        => $batch->id,
        'xml_document_id' => $document->id,
        'idempotency_key' => hash('sha256', $batch->id . '|' . $document->file_hash),
        'status'          => $status->value,
        'queue'           => 'tiss-send',
        'attempt'         => 1,
        'max_attempts'    => 5,
        'requested_at'    => now(),
    ]);
}

function gtxDocumentCount(TissBatch $batch): int
{
    return TissXmlDocument::query()->where('batch_id', $batch->id)->where('document_type', 'batch_submission')->count();
}

it('lote que já saiu para a operadora devolve o XML enviado: não cria documento, não mexe em status nem nas guias', function (TissBatchStatus $status) {
    $batch = gtxBatch();
    $sent  = gtxGenerate($batch);
    $batch->fresh()->guides()->update(['status' => 'sent']);
    $batch->fresh()->update(['status' => $status->value]);

    // Conteúdo e relógio diferentes do que foi enviado: ainda assim NADA se regera.
    $batch->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $returned = gtxGenerate($batch);

    expect($returned->is($sent))->toBeTrue()
        ->and(gtxDocumentCount($batch))->toBe(1)
        ->and($batch->fresh()->status)->toBe($status)
        ->and($batch->fresh()->guides()->pluck('tiss_guides.status')->map(fn ($s) => $s->value)->unique()->all())->toBe(['sent'])
        ->and($sent->fresh()->xml_content)->toContain('<CNES>1234567</CNES>');
})->with([
    'enviando'          => [TissBatchStatus::Sending],
    'enviado'           => [TissBatchStatus::Sent],
    'confirmado'        => [TissBatchStatus::Acknowledged],
    'processado'        => [TissBatchStatus::Processed],
    'parcialmente pago' => [TissBatchStatus::PartiallyPaid],
    'pago'              => [TissBatchStatus::Paid],
]);

it('lote marcado como enviado mas sem documento (estado incoerente) não trava: gera o XML', function () {
    $batch = gtxBatch();
    $batch->update(['status' => TissBatchStatus::Sent->value, 'xml_document_id' => null]);

    $document = gtxGenerate($batch);

    expect($document->exists)->toBeTrue()
        ->and(gtxDocumentCount($batch))->toBe(1);
});

it('documento já oferecido ao transporte e conteúdo igual: devolve o MESMO, mesmo um segundo depois — e o lote continua em erro', function () {
    $batch = gtxBatch();
    $first = gtxGenerate($batch);
    // A operadora RESPONDEU com erro (definitivo): lote em "erro", editável; sem
    // mudança de conteúdo o reenvio mantém o XML (e a chave). Sem resposta
    // (timeout) o lote fica "enviando" — coberto pelo dataset de status acima.
    gtxTransmit($batch, $first, TissTransmissionStatus::Failed);
    $batch->fresh()->update(['status' => TissBatchStatus::Error->value]);

    $this->travel(15)->seconds();

    $again = gtxGenerate($batch);

    expect($again->is($first))->toBeTrue()
        ->and(gtxDocumentCount($batch))->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($batch->fresh()->xml_document_id)->toBe($first->id);
});

it('documento já transmitido mas conteúdo mudado (dado cadastral): gera documento novo e o lote volta a "XML pronto"', function () {
    $batch = gtxBatch();
    $first = gtxGenerate($batch);
    gtxTransmit($batch, $first, TissTransmissionStatus::Failed);
    $batch->fresh()->update(['status' => TissBatchStatus::Error->value]);

    $batch->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();

    $second = gtxGenerate($batch);

    expect($second->is($first))->toBeFalse()
        ->and(gtxDocumentCount($batch))->toBe(2)
        ->and($second->xml_content)->toContain('<CNES>7654321</CNES>')
        ->and($second->file_hash)->not->toBe($first->file_hash)
        ->and($batch->fresh()->xml_document_id)->toBe($second->id)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::XmlReady);
});

it('documento que NUNCA foi ao transporte é regerado com o horário de agora (comportamento de sempre)', function () {
    $batch = gtxBatch();
    $first = gtxGenerate($batch);

    $this->travel(15)->seconds();

    $second = gtxGenerate($batch);

    expect($second->is($first))->toBeFalse()
        ->and(gtxDocumentCount($batch))->toBe(2)
        ->and($second->xml_content)->toContain('<horaRegistroTransacao>12:00:15</horaRegistroTransacao>');
});

it('transmissão de OUTRO documento do lote não conta: só vale transmissão sobre o documento atual', function () {
    $batch = gtxBatch();
    $old   = gtxGenerate($batch);
    gtxTransmit($batch, $old, TissTransmissionStatus::Failed);

    // Conteúdo mudou → documento novo (o atual); a transmissão ficou no antigo.
    $batch->entity->update(['cnes' => '7654321']);
    $this->travel(15)->seconds();
    $current = gtxGenerate($batch);

    // Nada mudou desde então, mas o documento atual nunca foi ao transporte.
    $this->travel(15)->seconds();
    $next = gtxGenerate($batch);

    expect($current->is($old))->toBeFalse()
        ->and($next->is($current))->toBeFalse()
        ->and(gtxDocumentCount($batch))->toBe(3);
});

it('documento transmitido porém inválido não é reaproveitado', function () {
    $batch = gtxBatch();
    $first = gtxGenerate($batch);
    gtxTransmit($batch, $first, TissTransmissionStatus::Failed);
    $first->update(['status' => TissXmlStatus::Invalid->value]);

    $this->travel(15)->seconds();

    $second = gtxGenerate($batch);

    expect($second->is($first))->toBeFalse()
        ->and(gtxDocumentCount($batch))->toBe(2);
});

it('lote de outra clínica não vaza: a transmissão é procurada só na clínica do lote', function () {
    $batch = gtxBatch();
    $first = gtxGenerate($batch);

    // Mesma estrutura de uma transmissão sobre o documento, mas de OUTRA clínica.
    $stranger = Entity::factory()->create(['is_client' => true]);
    TissTransmission::query()->create([
        'entity_id'       => $stranger->id,
        'operator_id'     => $batch->operator_id,
        'batch_id'        => $batch->id,
        'xml_document_id' => $first->id,
        'idempotency_key' => hash('sha256', 'outra-clinica'),
        'status'          => TissTransmissionStatus::Failed->value,
        'queue'           => 'tiss-send',
        'attempt'         => 1,
        'max_attempts'    => 5,
        'requested_at'    => now(),
    ]);

    $this->travel(15)->seconds();

    expect(gtxGenerate($batch)->is($first))->toBeFalse()
        ->and(gtxDocumentCount($batch))->toBe(2);
});

it('lê o status do banco, não da instância recebida: lote que acabou de ser enviado não é regerado por quem tinha uma cópia velha', function () {
    $batch = gtxBatch();
    $sent  = gtxGenerate($batch);
    $stale = $batch->fresh(); // cópia em memória: ainda "XML pronto"

    // Outro processo confirma o envio entre a leitura e a geração.
    $batch->fresh()->update(['status' => TissBatchStatus::Sent->value]);
    $this->travel(15)->seconds();

    $returned = app(GenerateTissXmlService::class)->generateBatch($stale);

    expect($stale->status)->toBe(TissBatchStatus::Sent)
        ->and($returned->is($sent))->toBeTrue()
        ->and(gtxDocumentCount($batch))->toBe(1)
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[ORDEM DE LOCKS] trava a linha do lote antes de ler o status e antes de gravar qualquer documento', function () {
    $batch   = gtxBatch();
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    gtxGenerate($batch);

    $lockAt   = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "tiss_batches"') && str_contains($sql, 'for update'));
    $insertAt = collect($queries)->search(fn (string $sql) => str_contains($sql, 'insert into "tiss_xml_documents"'));

    expect($lockAt)->not->toBeFalse()
        ->and($insertAt)->not->toBeFalse()
        ->and($lockAt)->toBeLessThan($insertAt);

    // Antes de qualquer UPDATE do lote (o primeiro update já trava a linha, mas só depois de ler o status).
    $firstBatchUpdate = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'update "tiss_batches"'));
    expect($lockAt)->toBeLessThan($firstBatchUpdate);
});

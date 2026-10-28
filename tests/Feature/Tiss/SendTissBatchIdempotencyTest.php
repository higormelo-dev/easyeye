<?php

declare(strict_types=1);

/**
 * Idempotência do envio de lote TISS — achado via auditoria de encerramento,
 * EE-08: reserveIdempotency() fazia updateOrCreate() SEM checar o status
 * atual, sobrescrevendo 'completed' para 'reserved' em toda chamada — a
 * chave persistida virava "não concluída" mesmo tendo sido enviada com
 * sucesso, e send() reenviaria em vez de devolver a transmissão existente.
 * Sonda da própria auditoria (sondas.json): status_before=completed,
 * status_after=reserved.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissOperator, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\SendTissBatchService;
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{entity: Entity, operator: TissOperator, batch: TissBatch, xmlDocument: TissXmlDocument, idempotencyKey: string}
 */
function tissSendFixture(): array
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create([
        'ans_code' => '123456',
        'name'     => 'Operadora Teste',
    ]);

    TissEntityOperatorCredential::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'environment' => (string) config('tiss.transport.environment', 'production'),
        'active'      => true,
    ]);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::XmlReady->value,
    ]);

    $xmlContent = '<ansTISS><mensagemTISS>teste</mensagemTISS></ansTISS>';
    $fileHash   = hash('sha256', $xmlContent);

    $xmlDocument = TissXmlDocument::create([
        'entity_id'     => $entity->id,
        'operator_id'   => $operator->id,
        'batch_id'      => $batch->id,
        'document_type' => 'batch_submission',
        'direction'     => TissXmlDirection::Outbound->value,
        'status'        => TissXmlStatus::Validated->value,
        'file_path'     => "tiss/{$entity->id}/batches/{$batch->id}.xml",
        'file_hash'     => $fileHash,
        'xml_content'   => $xmlContent,
        'generated_at'  => now(),
        'validated_at'  => now(),
    ]);

    $batch->update(['xml_document_id' => $xmlDocument->id]);

    // Mesma fórmula de SendTissBatchService::buildIdempotencyKey() (privado) —
    // precisamos do valor pra pré-inserir uma transmissão "concorrente" nos
    // testes de corrida.
    $idempotencyKey = hash('sha256', implode('|', [(string) $entity->id, (string) $batch->id, $fileHash]));

    return compact('entity', 'operator', 'batch', 'xmlDocument', 'idempotencyKey');
}

it('[GAP] repetir envio de lote já concluído devolve a MESMA transmissão — chave continua completed (EE-08)', function () {
    $f = tissSendFixture();

    $first = app(SendTissBatchService::class)->send($f['batch']->fresh());

    $key = TissIdempotencyKey::query()->where('key', $f['idempotencyKey'])->sole();
    expect($key->status)->toBe('completed')
        ->and(TissTransmission::query()->count())->toBe(1);

    $second = app(SendTissBatchService::class)->send($f['batch']->fresh());

    expect($second->id)->toBe($first->id)
        ->and(TissTransmission::query()->count())->toBe(1)
        ->and($key->fresh()->status)->toBe('completed');
});

it('[GAP] transmissão concorrente já existente pra mesma chave é adotada, sem erro de unicidade (EE-08)', function () {
    $f = tissSendFixture();

    // Simula outra chamada que já criou a transmissão pra esta MESMA chave —
    // mesma corrida que tiss_transmissions_entity_idempotency_unique
    // resolve de verdade entre duas conexões reais.
    $rival = TissTransmission::query()->create([
        'entity_id'       => $f['entity']->id,
        'operator_id'     => $f['operator']->id,
        'batch_id'        => $f['batch']->id,
        'xml_document_id' => $f['xmlDocument']->id,
        'idempotency_key' => $f['idempotencyKey'],
        'status'          => 'acknowledged',
        'queue'           => 'tiss-send',
        'attempt'         => 1,
        'max_attempts'    => 5,
        'requested_at'    => now(),
    ]);

    $result = app(SendTissBatchService::class)->send($f['batch']->fresh());

    expect($result->id)->toBe($rival->id)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('force=true com o MESMO conteúdo (mesma chave) continua devolvendo a transmissão existente — a unicidade não é contornada', function () {
    // A chave de idempotência é por CONTEÚDO (entity+batch+hash do XML);
    // force=true pula só o atalho do status 'completed', não o índice único
    // de tiss_transmissions. Forçar reenvio do MESMO conteúdo não pode gerar
    // uma segunda linha — "não remover a restrição única pra contornar o
    // erro" (RELATORIO.md, EE-08).
    $f = tissSendFixture();

    $first = app(SendTissBatchService::class)->send($f['batch']->fresh());
    expect(TissIdempotencyKey::query()->where('key', $f['idempotencyKey'])->sole()->status)->toBe('completed');

    $second = app(SendTissBatchService::class)->send($f['batch']->fresh(), force: true);

    expect($second->id)->toBe($first->id)
        ->and(TissTransmission::query()->count())->toBe(1);
});

it('reenviar depois do XML ser REGENERADO (conteúdo mudou) usa uma chave nova e cria uma transmissão nova', function () {
    $f = tissSendFixture();

    app(SendTissBatchService::class)->send($f['batch']->fresh());
    expect(TissTransmission::query()->count())->toBe(1);

    // Conteúdo mudou de verdade (ex.: lote corrigido e reemitido) — chave de
    // idempotência É outra, então a transmissão nova não colide com a 1ª.
    $newXmlContent = '<ansTISS><mensagemTISS>corrigido</mensagemTISS></ansTISS>';
    $f['xmlDocument']->update([
        'xml_content' => $newXmlContent,
        'file_hash'   => hash('sha256', $newXmlContent),
    ]);

    $second = app(SendTissBatchService::class)->send($f['batch']->fresh());

    expect(TissTransmission::query()->count())->toBe(2)
        ->and($second->idempotency_key)->not->toBe($f['idempotencyKey']);
});

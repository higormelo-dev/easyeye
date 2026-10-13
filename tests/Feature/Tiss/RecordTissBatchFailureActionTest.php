<?php

declare(strict_types=1);

/**
 * Falha DEFINITIVA de um job do lote TISS (RecordTissBatchFailureAction) — a
 * política de quais status viram "erro" e a trava da linha do lote. A corrida com
 * outra sessão, de verdade, está em tests/Integration/TissFailureHandlerRaceTest.php.
 */

use App\Domains\Tiss\Actions\RecordTissBatchFailureAction;
use App\Domains\Tiss\Enums\TissBatchStatus;
use App\Domains\Tiss\Models\{TissBatch, TissOperator};
use App\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rtbfBatch(TissBatchStatus $status, ?Entity $entity = null): TissBatch
{
    $entity ??= Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    return TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-' . strtoupper(substr(md5($status->value . $entity->id), 0, 6)),
        'reference_month' => now()->format('Y-m'),
        'status'          => $status->value,
    ]);
}

it('lote que ainda não saiu vai a "erro" com o motivo — e a ação diz que levou', function (TissBatchStatus $status) {
    $batch = rtbfBatch($status);

    $moved = app(RecordTissBatchFailureAction::class)((string) $batch->id, (string) $batch->entity_id, 'falha definitiva');

    expect($moved)->toBeTrue()
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($batch->fresh()->last_error)->toBe('falha definitiva');
})->with([
    TissBatchStatus::Open,
    TissBatchStatus::Closed,
    TissBatchStatus::XmlGenerating,
    TissBatchStatus::XmlReady,
    TissBatchStatus::Error,
]);

it('lote que já saiu (enviando, enviado, confirmado, processado, parcialmente pago, pago) ou foi cancelado NUNCA vira "erro" e nada nele é tocado', function (TissBatchStatus $status) {
    $batch = rtbfBatch($status);
    $batch->update(['last_error' => 'erro anterior']);

    $moved = app(RecordTissBatchFailureAction::class)((string) $batch->id, (string) $batch->entity_id, 'falha definitiva');

    expect($moved)->toBeFalse()
        ->and($batch->fresh()->status)->toBe($status)
        ->and($batch->fresh()->last_error)->toBe('erro anterior');
})->with([
    TissBatchStatus::Sending,
    TissBatchStatus::Sent,
    TissBatchStatus::Acknowledged,
    TissBatchStatus::Processed,
    TissBatchStatus::PartiallyPaid,
    TissBatchStatus::Paid,
    TissBatchStatus::Cancelled,
]);

it('em "enviando" o envio registra o motivo (a dúvida segue travada); a geração do XML não toca em nada', function () {
    $batch = rtbfBatch(TissBatchStatus::Sending);
    $batch->update(['last_error' => 'cURL error 28']);

    app(RecordTissBatchFailureAction::class)((string) $batch->id, (string) $batch->entity_id, 'falha ao enviar', recordWhileSending: true);

    expect($batch->fresh()->status)->toBe(TissBatchStatus::Sending)
        ->and($batch->fresh()->last_error)->toBe('falha ao enviar');

    app(RecordTissBatchFailureAction::class)((string) $batch->id, (string) $batch->entity_id, 'falha ao gerar XML');

    expect($batch->fresh()->last_error)->toBe('falha ao enviar');
});

it('[SEGURANÇA] lote de OUTRA clínica não é tocado: o id do lote só vale com a clínica do job', function () {
    $mine  = rtbfBatch(TissBatchStatus::XmlReady);
    $other = Entity::factory()->create(['is_client' => true]);

    $moved = app(RecordTissBatchFailureAction::class)((string) $mine->id, (string) $other->id, 'falha definitiva');

    expect($moved)->toBeFalse()
        ->and($mine->fresh()->status)->toBe(TissBatchStatus::XmlReady)
        ->and($mine->fresh()->last_error)->toBeNull();
});

it('job sem clínica informada ainda acha o lote pelo id; lote inexistente é um no-op', function () {
    $batch = rtbfBatch(TissBatchStatus::XmlReady);

    expect(app(RecordTissBatchFailureAction::class)((string) $batch->id, null, 'falha definitiva'))->toBeTrue()
        ->and($batch->fresh()->status)->toBe(TissBatchStatus::Error);

    expect(app(RecordTissBatchFailureAction::class)('00000000-0000-0000-0000-000000000000', null, 'falha definitiva'))->toBeFalse();
});

it('lê o lote COM a trava da linha (FOR UPDATE), dentro da transação do update — a leitura e a escrita são atômicas', function () {
    $batch = rtbfBatch(TissBatchStatus::XmlReady);

    $events = [];

    DB::listen(function (QueryExecuted $query) use (&$events): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, '"tiss_batches"') && (str_starts_with($sql, 'select') || str_starts_with($sql, 'update'))) {
            $events[] = [str_contains($sql, 'for update') ? 'lock' : (str_starts_with($sql, 'update') ? 'update' : 'select'), DB::transactionLevel()];
        }
    });

    // O teste roda dentro da transação do RefreshDatabase (nível 1): a da ação é um SAVEPOINT (nível 2).
    app(RecordTissBatchFailureAction::class)((string) $batch->id, (string) $batch->entity_id, 'falha definitiva');

    $kinds = array_column($events, 0);

    expect($kinds)->toContain('lock')
        ->and($kinds)->not->toContain('select')
        ->and(array_search('lock', $kinds, true))->toBeLessThan(array_search('update', $kinds, true))
        // lock e update na MESMA transação (aninhada) da ação
        ->and(collect($events)->firstWhere(0, 'lock')[1])->toBe(collect($events)->firstWhere(0, 'update')[1])
        ->and(collect($events)->firstWhere(0, 'lock')[1])->toBeGreaterThan(1);
});

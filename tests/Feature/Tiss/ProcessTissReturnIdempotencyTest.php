<?php

declare(strict_types=1);

/**
 * Processamento do retorno TISS (ProcessTissReturnService) é IDEMPOTENTE — achado
 * via revisão adversarial Codex (25ª rodada). Com o worker da fila `tiss-returns`
 * ligado (EE-09), o job é entregue "pelo menos uma vez": se o worker morre
 * depois de comitar o retorno e antes de confirmar o job, ele volta à fila e o
 * retorno é processado DE NOVO. Nada o impedia: ProcessGlosaReturnService faz
 * upsert da glosa mas SOMA o valor outra vez em guide.denied_amount (50 virava 100
 * e o pago caía de 100 para 50), e o resto do processamento reescrevia protocolo,
 * lote e guias com o desfecho DAQUELE retorno — mesmo que um retorno mais novo já
 * tivesse andado.
 *
 * Agora o processamento trava o lote e depois a linha do retorno, relê o retorno
 * e, se ele já está `processed`, devolve sem tocar em nada. Efeitos financeiros e
 * marca `processed` seguem na MESMA transação. A corrida de verdade, com duas
 * conexões, está em tests/Integration/TissReturnRedeliveryTest.php.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Jobs\ProcessTissReturnJob;
use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn, TissStatusHistory};
use App\Domains\Tiss\Services\ProcessTissReturnService;
use App\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Glosa de R$ 50 na guia GUI-0001 (total R$ 150). */
const PTI_GLOSA = ['code' => '3010', 'description' => 'Glosa teste', 'amount' => 50.0, 'guide_number_provider' => 'GUI-0001'];

/**
 * Lote enviado com 2 guias, protocolo recebido e um retorno ainda não processado.
 *
 * @param array<string, mixed> $summary
 *
 * @return array{batch: TissBatch, protocol: TissProtocol, return: TissReturn, guides: list<TissGuide>}
 */
function ptiFixture(array $summary): array
{
    Carbon::setTestNow('2026-10-13 12:00:00');

    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-REENTREGA-0001',
        'reference_month' => now()->format('Y-m'),
        'status'          => TissBatchStatus::Sent->value,
    ]);

    $guides = [];

    foreach (['GUI-0001' => 150, 'GUI-0002' => 200] as $number => $total) {
        $guide = TissGuide::create([
            'entity_id'             => $entity->id,
            'operator_id'           => $operator->id,
            'guide_type'            => 'consultation',
            'guide_number_provider' => $number,
            'status'                => TissGuideStatus::Sent->value,
            'attendance_date'       => now()->toDateString(),
            'total_amount'          => $total,
        ]);

        DB::table('tiss_batch_guides')->insert([
            'id'          => (string) Str::uuid(),
            'entity_id'   => $entity->id,
            'batch_id'    => $batch->id,
            'guide_id'    => $guide->id,
            'attached_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $guides[] = $guide;
    }

    $protocol = TissProtocol::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_id'        => $batch->id,
        'protocol_number' => 'PRT-REENTREGA-0001',
        'status'          => TissProtocolStatus::Received->value,
        'details'         => ['source' => 'batch_send'],
    ]);

    $fixture = ['batch' => $batch, 'protocol' => $protocol, 'guides' => $guides];

    $fixture['return'] = ptiReturn($fixture, $summary);

    return $fixture;
}

/**
 * Outro retorno do mesmo protocolo.
 *
 * @param array{batch: TissBatch, protocol: TissProtocol} $f
 * @param array<string, mixed>                            $summary
 */
function ptiReturn(array $f, array $summary): TissReturn
{
    return TissReturn::create([
        'entity_id'   => $f['batch']->entity_id,
        'operator_id' => $f['batch']->operator_id,
        'protocol_id' => $f['protocol']->id,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => $summary,
    ]);
}

/**
 * Tudo o que o processamento de um retorno escreve, como está no banco agora
 * (datas cruas: reescrever só o `processed_at` já muda o retrato).
 *
 * @param list<TissReturn> $returns
 *
 * @return array<string, mixed>
 */
function ptiSnapshot(array $f, array $returns): array
{
    return [
        'guides' => collect($f['guides'])->map(function (TissGuide $guide): array {
            $fresh = $guide->fresh();

            return [$fresh->status->value, (float) $fresh->denied_amount, (float) $fresh->paid_amount];
        })->all(),
        'batch' => (function () use ($f): array {
            $fresh = $f['batch']->fresh();

            return [$fresh->status->value, $fresh->getRawOriginal('processed_at'), $fresh->last_error];
        })(),
        'protocol' => (function () use ($f): array {
            $fresh = $f['protocol']->fresh();

            return [$fresh->status->value, $fresh->getRawOriginal('processed_at'), $fresh->details];
        })(),
        'returns' => collect($returns)->map(function (TissReturn $return): array {
            $fresh = $return->fresh();

            return [$fresh->status->value, $fresh->getRawOriginal('processed_at'), $fresh->summary];
        })->all(),
        'glosas'  => TissGlosa::query()->count(),
        'history' => TissStatusHistory::query()->count(),
    ];
}

it('[IDEMPOTÊNCIA] reentrega do MESMO retorno (instância velha, ainda "parsed" em memória) não soma a glosa de novo nem reescreve nada', function () {
    $f = ptiFixture(['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);

    // A instância que o job reentregue ainda tem na mão: carregada ANTES de o
    // primeiro processamento comitar, com o status antigo.
    $stale = TissReturn::query()->findOrFail($f['return']->id);

    app(ProcessTissReturnService::class)->process($f['return']);

    $first = ptiSnapshot($f, [$f['return']]);

    expect($first['guides'][0])->toBe(['partially_denied', 50.0, 100.0])
        ->and($first['guides'][1])->toBe(['paid', 0.0, 0.0]);

    $this->travel(30)->seconds();

    $again = app(ProcessTissReturnService::class)->process($stale);

    expect($again->status)->toBe(TissReturnStatus::Processed)
        ->and(ptiSnapshot($f, [$f['return']]))->toBe($first);
});

it('[IDEMPOTÊNCIA] o job reentregue (worker morto depois do commit, antes de confirmar) termina sem somar de novo', function () {
    $f   = ptiFixture(['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);
    $job = new ProcessTissReturnJob((string) $f['return']->id, (string) $f['return']->entity_id);

    $job->handle(app(ProcessTissReturnService::class));

    $first = ptiSnapshot($f, [$f['return']]);

    $this->travel(30)->seconds();

    // Reentrega: o mesmo job de novo, sem exceção.
    $job->handle(app(ProcessTissReturnService::class));

    expect(ptiSnapshot($f, [$f['return']]))->toBe($first)
        ->and((float) $f['guides'][0]->fresh()->denied_amount)->toBe(50.0)
        ->and((float) $f['guides'][0]->fresh()->paid_amount)->toBe(100.0);
});

it('[IDEMPOTÊNCIA] reentrega de um retorno ANTIGO não desfaz o desfecho de um retorno mais novo', function () {
    // A: a operadora recebeu e está analisando. B: aceitou, com uma glosa.
    $f = ptiFixture(['status' => 'processing']);
    $b = ptiReturn($f, ['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);

    $service = app(ProcessTissReturnService::class);
    $service->process($f['return']);
    $service->process($b);

    $final = ptiSnapshot($f, [$f['return'], $b]);

    expect($final['batch'][0])->toBe(TissBatchStatus::PartiallyPaid->value)
        ->and($final['protocol'][0])->toBe(TissProtocolStatus::Accepted->value)
        ->and($final['guides'][0])->toBe(['partially_denied', 50.0, 100.0]);

    $this->travel(30)->seconds();

    // O job de A volta à fila e roda por último: antes, lote, protocolo e guias
    // regrediam para "em análise" e o retorno A era "processado" outra vez.
    (new ProcessTissReturnJob((string) $f['return']->id, (string) $f['return']->entity_id))->handle($service);

    expect(ptiSnapshot($f, [$f['return'], $b]))->toBe($final);
});

it('retorno em erro, recebido ou parseado ainda é processado — só o `processed` é final', function (string $status) {
    $f = ptiFixture(['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);
    $f['return']->update(['status' => $status, 'error_message' => $status === 'error' ? 'falha anterior' : null]);

    $processed = app(ProcessTissReturnService::class)->process($f['return']->fresh());

    expect($processed->status)->toBe(TissReturnStatus::Processed)
        ->and((float) $f['guides'][0]->fresh()->denied_amount)->toBe(50.0)
        ->and((float) $f['guides'][0]->fresh()->paid_amount)->toBe(100.0)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::PartiallyPaid)
        ->and(TissGlosa::query()->count())->toBe(1);
})->with(['erro' => ['error'], 'recebido' => ['received'], 'parseado' => ['parsed']]);

it('retorno apagado entre a leitura e a trava não é processado', function () {
    $f     = ptiFixture(['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);
    $stale = TissReturn::query()->findOrFail($f['return']->id);

    $f['return']->delete();

    $result = app(ProcessTissReturnService::class)->process($stale);

    expect($result->is($stale))->toBeTrue()
        ->and(TissGlosa::query()->count())->toBe(0)
        ->and((float) $f['guides'][0]->fresh()->denied_amount)->toBe(0.0)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Sent)
        ->and(TissStatusHistory::query()->count())->toBe(0);
});

it('failed() do job não reabre um retorno já processado, mas marca em erro o que não foi', function (TissReturnStatus $status, TissReturnStatus $expected, bool $hasMessage) {
    $f = ptiFixture(['status' => 'accepted']);
    $f['return']->update(['status' => $status->value]);

    (new ProcessTissReturnJob((string) $f['return']->id, (string) $f['return']->entity_id))
        ->failed(new RuntimeException('conexão perdida ao confirmar'));

    $return = $f['return']->fresh();

    expect($return->status)->toBe($expected)
        ->and($return->error_message !== null)->toBe($hasMessage);

    if ($hasMessage) {
        expect($return->error_message)->toContain('conexão perdida ao confirmar');
    }
})->with([
    'processado: continua processado' => [TissReturnStatus::Processed, TissReturnStatus::Processed, false],
    'parseado: vira erro'             => [TissReturnStatus::Parsed, TissReturnStatus::Error, true],
]);

it('[TRAVA] failed() lê o retorno com FOR UPDATE, dentro de uma transação, e só então decide se escreve', function () {
    $f = ptiFixture(['status' => 'accepted']);

    $baseline = DB::transactionLevel();
    $locks    = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        }
    });

    (new ProcessTissReturnJob((string) $f['return']->id, (string) $f['return']->entity_id))
        ->failed(new RuntimeException('conexão perdida ao confirmar'));

    // A trava só vale dentro de uma transação aberta pelo próprio failed(): fora dela
    // o FOR UPDATE se solta no mesmo instante e a decisão volta a ser sobre um retrato.
    expect($locks)->toHaveCount(1)
        ->and($locks[0]['sql'])->toContain('"tiss_returns"')
        ->and($locks[0]['level'])->toBeGreaterThan($baseline)
        ->and($f['return']->fresh()->status)->toBe(TissReturnStatus::Error);
});

it('[ORDEM DE LOCKS] a linha do retorno é travada DEPOIS do lote e ANTES de qualquer escrita, e relida sob a trava', function () {
    $f = ptiFixture(['status' => 'accepted', 'glosas' => [PTI_GLOSA]]);

    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $sql = strtolower($query->sql);

        foreach (['tiss_batches', 'tiss_protocols', 'tiss_guides', 'tiss_glosas', 'tiss_returns'] as $table) {
            if (! str_contains($sql, '"' . $table . '"')) {
                continue;
            }

            $kind = match (true) {
                str_starts_with($sql, 'update')  => 'update',
                str_starts_with($sql, 'insert')  => 'insert',
                str_contains($sql, 'for update') => 'lock',
                str_starts_with($sql, 'select')  => 'select',
                default                          => null,
            };

            if ($kind !== null) {
                $statements[] = [$kind, $table];
            }
        }
    });

    app(ProcessTissReturnService::class)->process($f['return']);

    $positions = fn (string $kind, string $table): array => array_keys(array_filter(
        $statements,
        fn (array $s): bool => $s === [$kind, $table],
    ));

    $batchLock  = $positions('lock', 'tiss_batches');
    $returnLock = $positions('lock', 'tiss_returns');

    expect($batchLock)->toHaveCount(1)
        ->and($returnLock)->toHaveCount(1)
        // lote → retorno (a ordem única do TISS: o lote é sempre a 1ª trava)...
        ->and($returnLock[0])->toBeGreaterThan($batchLock[0]);

    // ...antes de QUALQUER escrita do processamento (inclusive a marca `processed` do próprio retorno)...
    foreach ([['update', 'tiss_protocols'], ['update', 'tiss_guides'], ['update', 'tiss_batches'], ['insert', 'tiss_glosas'], ['update', 'tiss_returns']] as [$kind, $table]) {
        expect(min($positions($kind, $table)), "{$kind} {$table} antes da trava do retorno")->toBeGreaterThan($returnLock[0]);
    }

    // ...e o retorno é relido DEPOIS dela (a decisão de pular vale o que está comitado, não a instância).
    expect(array_filter($positions('select', 'tiss_returns'), fn (int $p): bool => $p > $returnLock[0]))->not->toBeEmpty();
});

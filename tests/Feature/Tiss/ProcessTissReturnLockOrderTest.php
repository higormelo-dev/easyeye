<?php

declare(strict_types=1);

/**
 * Processamento do retorno TISS (ProcessTissReturnService) — ordem das travas e
 * comportamento. Achado via revisão adversarial Codex (21ª rodada): o
 * processamento atualizava o protocolo (e as guias, via glosas) ANTES de tocar o
 * lote, o inverso do envio (lote → transmissão → protocolo → guias), inclusive do
 * reenvio forçado de transmissão confirmada, que atualiza o protocolo existente
 * com o lote travado. Dois lados esperando um ao outro = deadlock, e o
 * PostgreSQL abortava um deles (o reenvio podia falhar com a operadora já tendo
 * aceitado). A corrida de verdade, com duas conexões, está em
 * tests/Integration/TissReturnResendLockOrderTest.php.
 */

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissReturnStatus};
use App\Domains\Tiss\Models\{TissBatch, TissGlosa, TissGuide, TissOperator, TissProtocol, TissReturn};
use App\Domains\Tiss\Services\ProcessTissReturnService;
use App\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Lote enviado com 2 guias, protocolo recebido e um retorno ainda não processado.
 *
 * @param array<string, mixed> $summary
 *
 * @return array{batch: TissBatch, protocol: TissProtocol, return: TissReturn, guides: list<TissGuide>}
 */
function ptrFixture(array $summary): array
{
    $entity   = Entity::factory()->create(['is_client' => true]);
    $operator = TissOperator::create(['ans_code' => '123456', 'name' => 'Operadora Teste']);

    $batch = TissBatch::create([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'batch_number'    => 'LOTE-RETORNO-0001',
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
        'protocol_number' => 'PRT-RETORNO-0001',
        'status'          => TissProtocolStatus::Received->value,
        'details'         => ['source' => 'batch_send'],
    ]);

    $return = TissReturn::create([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
        'protocol_id' => $protocol->id,
        'return_type' => 'batch_return',
        'status'      => TissReturnStatus::Parsed->value,
        'received_at' => now(),
        'summary'     => $summary,
    ]);

    return ['batch' => $batch, 'protocol' => $protocol, 'return' => $return, 'guides' => $guides];
}

it('retorno aceito: protocolo aceito, lote processado, guias pagas, retorno processado', function () {
    $f = ptrFixture(['status' => 'accepted']);

    $processed = app(ProcessTissReturnService::class)->process($f['return']);

    expect($processed->status)->toBe(TissReturnStatus::Processed)
        ->and($f['protocol']->fresh()->status)->toBe(TissProtocolStatus::Accepted)
        ->and($f['protocol']->fresh()->details['return_id'])->toBe((string) $f['return']->id)
        ->and($f['protocol']->fresh()->details['source'])->toBe('batch_send')
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Processed)
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Paid]);
});

it('retorno aceito com glosa: lote parcialmente pago, a guia glosada parcialmente negada e a outra paga', function () {
    $f = ptrFixture(['status' => 'accepted', 'glosas' => [
        ['code' => '3010', 'description' => 'Glosa teste', 'amount' => 50.0, 'guide_number_provider' => 'GUI-0001'],
    ]]);

    app(ProcessTissReturnService::class)->process($f['return']);

    expect($f['batch']->fresh()->status)->toBe(TissBatchStatus::PartiallyPaid)
        ->and($f['guides'][0]->fresh()->status)->toBe(TissGuideStatus::PartiallyDenied)
        ->and((float) $f['guides'][0]->fresh()->denied_amount)->toBe(50.0)
        ->and($f['guides'][1]->fresh()->status)->toBe(TissGuideStatus::Paid)
        ->and(TissGlosa::query()->count())->toBe(1);
});

it('retorno rejeitado: protocolo rejeitado, lote em erro com o motivo, guias negadas', function () {
    $f = ptrFixture(['status' => 'rejected']);

    app(ProcessTissReturnService::class)->process($f['return']);

    expect($f['protocol']->fresh()->status)->toBe(TissProtocolStatus::Rejected)
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Error)
        ->and($f['batch']->fresh()->last_error)->toBe('Lote rejeitado em retorno da operadora.')
        ->and(collect($f['guides'])->map(fn (TissGuide $g) => $g->fresh()->status)->unique()->all())->toBe([TissGuideStatus::Denied]);
});

it('retorno sem protocolo (ainda não casado com um lote): só o retorno é marcado como processado, sem travar lote nenhum', function () {
    $f = ptrFixture(['status' => 'accepted']);
    $f['return']->update(['protocol_id' => null]);

    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = $query->sql;
        }
    });

    $processed = app(ProcessTissReturnService::class)->process($f['return']->fresh());

    // Sem lote para travar, só a linha do próprio retorno (a trava que torna o
    // processamento idempotente — ProcessTissReturnIdempotencyTest).
    expect($processed->status)->toBe(TissReturnStatus::Processed)
        ->and($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('"tiss_returns"')
        ->and($locks[0])->not->toContain('"tiss_batches"')
        ->and($f['batch']->fresh()->status)->toBe(TissBatchStatus::Sent);
});

it('[ORDEM DE LOCKS] a linha do lote é travada (FOR UPDATE) ANTES de qualquer escrita em protocolo, guia ou glosa, e protocolo/lote/guias são relidos DEPOIS da trava', function () {
    $f = ptrFixture(['status' => 'accepted', 'glosas' => [
        ['code' => '3010', 'description' => 'Glosa teste', 'amount' => 50.0, 'guide_number_provider' => 'GUI-0001'],
    ]]);

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

    $lock = $positions('lock', 'tiss_batches');

    expect($lock)->not->toBeEmpty();

    $firstLock = $lock[0];

    // Nenhuma escrita antes da trava do lote...
    foreach ([['update', 'tiss_protocols'], ['update', 'tiss_guides'], ['update', 'tiss_batches'], ['insert', 'tiss_glosas']] as [$kind, $table]) {
        $written = $positions($kind, $table);

        expect($written, "{$kind} {$table}")->not->toBeEmpty()
            ->and(min($written), "{$kind} {$table} antes da trava")->toBeGreaterThan($firstLock);
    }

    // ...e o que decide a escrita (guias do lote) é lido DEPOIS dela.
    expect(min($positions('select', 'tiss_guides')))->toBeGreaterThan($firstLock);
});

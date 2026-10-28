<?php

declare(strict_types=1);

/**
 * Cancelar guia/lote e corrigir pendência × o resto do faturamento — ordem de
 * locks documentada em BillingService (advisory do lote → lote → guias por id
 * → lote TISS → guias TISS; nada de numeração nem de `schedules`).
 *
 * Contenção real, como em CashPeriodLockTest: uma SEGUNDA sessão PostgreSQL
 * (a "outra requisição") segura o lock com lock_timeout próprio, e a sessão do
 * teste usa `set local lock_timeout = '300ms'` — 55P03 = ficou esperando.
 * Para a outra sessão enxergar as linhas, elas são gravadas e COMMITADAS por
 * ela (fora da transação do RefreshDatabase) e apagadas depois do rollback do
 * teste. A outra sessão sempre pega o lock ANTES de a sessão do teste tocar
 * nas linhas (nunca o contrário, senão ela é que ficaria presa).
 */

use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity};
use App\Services\Financial\{BillingAdjustmentService, BillingEditLocks, BillingService};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const BCL_PROBE = 'pgsql_lock_probe_billing_cancel';

const BCL_REASON = 'Motivo de teste de concorrência';

function bclProbe(): Connection
{
    config(['database.connections.' . BCL_PROBE => config('database.connections.' . config('database.default'))]);

    return DB::connection(BCL_PROBE);
}

function bclRelease(Connection $probe): void
{
    while ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }
}

/** Mesma chave do advisory de edição do lote (BillingEditLocks). */
function bclEditKey(string $batchId): int
{
    return app(BillingEditLocks::class)->key($batchId);
}

/** Mesma chave da numeração de HasEntityCode (60 bits do sha1). */
function bclNumberingKey(string ...$parts): int
{
    return (int) hexdec(substr(sha1(implode('|', $parts)), 0, 15));
}

/**
 * Clínica + convênio particular + lote com guias, gravados e COMMITADOS pela
 * outra sessão (autocommit). Guias em ordem crescente de id. A limpeza roda
 * depois do rollback do teste (beforeApplicationDestroyed registrado depois do
 * RefreshDatabase).
 *
 * @return array{entity_id: string, covenant_id: string, batch_id: string, claim_ids: list<string>}
 */
function bclFixtures(Connection $probe, int $claims = 2, BillingBatchStatus $batchStatus = BillingBatchStatus::Draft, BillingClaimStatus $claimStatus = BillingClaimStatus::Draft): array
{
    $now      = now();
    $suffix   = Str::upper(Str::random(8));
    $entityId = (string) Str::uuid();
    $covenant = (string) Str::uuid();
    $batchId  = (string) Str::uuid();

    $probe->table('entities')->insert(array_merge(Entity::factory()->raw(), [
        'id' => $entityId, 'code' => "ENT-L{$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]));

    $probe->table('covenants')->insert(array_merge(Covenant::factory()->raw(['entity_id' => $entityId]), [
        'id' => $covenant, 'code' => "CV-L{$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]));

    $probe->table('billing_batches')->insert([
        'id'           => $batchId,
        'entity_id'    => $entityId,
        'covenant_id'  => $covenant,
        'code'         => "LOT-L{$suffix}",
        'status'       => $batchStatus->value,
        'period_start' => $now->toDateString(),
        'period_end'   => $now->toDateString(),
        'total_claims' => $claims,
        'total_amount' => 100 * $claims,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    $claimIds = collect(range(1, $claims))->map(fn () => (string) Str::uuid())->sort()->values()->all();

    foreach ($claimIds as $i => $claimId) {
        $probe->table('billing_claims')->insert([
            'id'              => $claimId,
            'entity_id'       => $entityId,
            'covenant_id'     => $covenant,
            'batch_id'        => $batchId,
            'code'            => "GUI-L{$suffix}-{$i}",
            'guide_number'    => "GUI-L{$suffix}-{$i}",
            'status'          => $claimStatus->value,
            'attendance_date' => $now->toDateString(),
            'amount'          => 100,
            'quantity'        => 1,
            'unit_price'      => 100,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    // beforeApplicationDestroyed é protegido: registra no escopo do caso de teste.
    // Roda depois do callback do RefreshDatabase (rollback), então nada da
    // sessão do teste segura mais estas linhas.
    (fn (callable $cleanup) => $this->beforeApplicationDestroyed($cleanup))->call(test(), fn () => bclCleanup($entityId));

    return ['entity_id' => $entityId, 'covenant_id' => $covenant, 'batch_id' => $batchId, 'claim_ids' => $claimIds];
}

function bclCleanup(string $entityId): void
{
    $probe = bclProbe();
    bclRelease($probe);

    // Sem lock_timeout infinito: se algo ainda segurar as linhas, falha em vez de travar a suíte.
    $probe->statement("set lock_timeout = '5s'");
    $probe->table('financial_cash_entries')->where('entity_id', $entityId)->delete();
    $probe->table('billing_claims')->where('entity_id', $entityId)->delete();
    $probe->table('billing_batches')->where('entity_id', $entityId)->delete();
    $probe->table('covenants')->where('entity_id', $entityId)->delete();
    $probe->table('entities')->where('id', $entityId)->delete();

    DB::purge(BCL_PROBE);
}

/** A outra sessão abre transação e trava a linha (lock_timeout próprio: se o teste já segurasse, falharia rápido). */
function bclHoldRow(Connection $probe, string $table, string $id): void
{
    $probe->beginTransaction();
    $probe->statement("set local lock_timeout = '2s'");
    $probe->select("select id from {$table} where id = ? for update", [$id]);
}

/** A sessão do teste desiste de esperar lock depois de 300ms (erro 55P03). */
function bclShortLockTimeout(): void
{
    DB::statement("set local lock_timeout = '300ms'");
}

function bclExpectLockTimeout(Closure $action): void
{
    expect($action)->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
}

function bclExpectBusy(Closure $action, string $batchCode): void
{
    expect($action)->toThrow(fn (ValidationException $e) => expect($e->errors()['batch'][0])
        ->toBe(__('financial_billing.errors.batch_busy', ['code' => $batchCode])));
}

function bclClaim(string $id): BillingClaim
{
    return BillingClaim::query()->findOrFail($id);
}

function bclBatch(string $id): BillingBatch
{
    return BillingBatch::query()->findOrFail($id);
}

describe('cancelar espera os locks de linha na ordem lote → guias', function (): void {
    it('cancelar guia espera quem segura a linha do LOTE (envio ou cancelamento do lote)', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);

        try {
            bclHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bclShortLockTimeout();

            bclExpectLockTimeout(fn () => app(BillingAdjustmentService::class)->cancelClaim(bclClaim($fx['claim_ids'][0]), BCL_REASON));
        } finally {
            bclRelease($probe);
        }

        // Solto o lote, cancela normalmente.
        expect(bclClaim($fx['claim_ids'][0])->status)->toBe(BillingClaimStatus::Draft)
            ->and(app(BillingAdjustmentService::class)->cancelClaim(bclClaim($fx['claim_ids'][0]), BCL_REASON)->status)->toBe(BillingClaimStatus::Cancelled);
    });

    it('cancelar guia espera quem segura a linha da GUIA (receber/glosar a mesma guia)', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);

        try {
            bclHoldRow($probe, 'billing_claims', $fx['claim_ids'][0]);
            bclShortLockTimeout();

            bclExpectLockTimeout(fn () => app(BillingAdjustmentService::class)->cancelClaim(bclClaim($fx['claim_ids'][0]), BCL_REASON));
        } finally {
            bclRelease($probe);
        }

        expect(bclClaim($fx['claim_ids'][0])->status)->toBe(BillingClaimStatus::Draft);
    });

    it('cancelar lote espera o lote e cada guia dele (por id): nada é cancelado pela metade', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 3);

        try {
            bclHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bclShortLockTimeout();

            bclExpectLockTimeout(fn () => app(BillingAdjustmentService::class)->cancelBatch(bclBatch($fx['batch_id']), BCL_REASON));
        } finally {
            bclRelease($probe);
        }

        try {
            // A última guia (maior id) presa na outra sessão: as anteriores já foram
            // travadas pelo teste, mas nada é gravado — o savepoint desfaz tudo.
            bclHoldRow($probe, 'billing_claims', end($fx['claim_ids']));
            bclShortLockTimeout();

            bclExpectLockTimeout(fn () => app(BillingAdjustmentService::class)->cancelBatch(bclBatch($fx['batch_id']), BCL_REASON));
        } finally {
            bclRelease($probe);
        }

        expect(bclBatch($fx['batch_id'])->status)->toBe(BillingBatchStatus::Draft)
            ->and(BillingClaim::query()->whereIn('id', $fx['claim_ids'])->where('status', BillingClaimStatus::Cancelled->value)->count())->toBe(0);
    });

    it('corrigir pendência também pede o lote antes da guia', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);

        try {
            bclHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bclShortLockTimeout();

            bclExpectLockTimeout(fn () => app(BillingAdjustmentService::class)->fixPendingGuide(bclClaim($fx['claim_ids'][0]), ['authorization_number' => '1']));
        } finally {
            bclRelease($probe);
        }
    });
});

describe('sem ciclo com os outros fluxos', function (): void {
    it('receber e glosar nunca pedem o lote: com a outra sessão segurando o lote, concluem na hora', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 2, batchStatus: BillingBatchStatus::Submitted, claimStatus: BillingClaimStatus::Submitted);

        try {
            // Quem cancela segura o lote e espera a guia; receber/glosar seguram só a
            // guia e nunca esperam o lote — as esperas não fecham ciclo.
            bclHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bclShortLockTimeout();

            $paid   = app(BillingService::class)->markClaimPaid(bclClaim($fx['claim_ids'][0]));
            $denied = app(BillingService::class)->markClaimDenied(bclClaim($fx['claim_ids'][1]), ['glosa_amount' => 40]);
        } finally {
            bclRelease($probe);
        }

        expect($paid->status)->toBe(BillingClaimStatus::Paid)
            ->and($denied->status)->toBe(BillingClaimStatus::Denied);
    });

    it('cancelar não pede numeração: com LOT/GUI (criar lote/guia) presos na outra sessão, conclui na hora', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 2);

        try {
            $probe->beginTransaction();
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock(?)', [bclNumberingKey('entity_code', 'billing_batches', $fx['entity_id'], 'LOT')]);
            $probe->select('select pg_advisory_xact_lock(?)', [bclNumberingKey('entity_code', 'billing_claims', $fx['entity_id'], 'GUI')]);
            bclShortLockTimeout();

            $claim = app(BillingAdjustmentService::class)->cancelClaim(bclClaim($fx['claim_ids'][0]), BCL_REASON);
            $batch = app(BillingAdjustmentService::class)->cancelBatch(bclBatch($fx['batch_id']), BCL_REASON);
        } finally {
            bclRelease($probe);
        }

        expect($claim->status)->toBe(BillingClaimStatus::Cancelled)
            ->and($batch->status)->toBe(BillingBatchStatus::Cancelled);
    });
});

describe('envio × cancelamento do mesmo lote (advisory do lote, só tentativa)', function (): void {
    it('envio em andamento (lock de sessão na outra conexão): cancelar e corrigir recusam na hora, sem esperar nem gravar', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);
        $code  = bclBatch($fx['batch_id'])->code;
        $key   = bclEditKey($fx['batch_id']);

        try {
            $probe->statement("set lock_timeout = '2s'");
            $probe->select('select pg_advisory_lock(?)', [$key]);
            bclShortLockTimeout();

            bclExpectBusy(fn () => app(BillingAdjustmentService::class)->cancelClaim(bclClaim($fx['claim_ids'][0]), BCL_REASON), $code);
            bclExpectBusy(fn () => app(BillingAdjustmentService::class)->cancelBatch(bclBatch($fx['batch_id']), BCL_REASON), $code);
            bclExpectBusy(fn () => app(BillingAdjustmentService::class)->fixPendingGuide(bclClaim($fx['claim_ids'][0]), []), $code);
        } finally {
            $probe->select('select pg_advisory_unlock(?)', [$key]);
            bclRelease($probe);
        }

        expect(bclBatch($fx['batch_id'])->status)->toBe(BillingBatchStatus::Draft)
            ->and(bclClaim($fx['claim_ids'][0])->status)->toBe(BillingClaimStatus::Draft);
    });

    it('cancelamento em andamento (lock de transação na outra conexão): enviar o lote recusa na hora', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);

        try {
            $probe->beginTransaction();
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock(?)', [bclEditKey($fx['batch_id'])]);

            bclExpectBusy(fn () => app(BillingService::class)->submitBatch(bclBatch($fx['batch_id'])), bclBatch($fx['batch_id'])->code);
        } finally {
            bclRelease($probe);
        }

        expect(bclBatch($fx['batch_id'])->status)->toBe(BillingBatchStatus::Draft);
    });

    it('o envio relê o lote com o lock (lote cancelado antes não vai) e solta o lock de sessão no fim', function (): void {
        $probe = bclProbe();
        $fx    = bclFixtures($probe, claims: 1);

        $stale = bclBatch($fx['batch_id']);
        // Outra requisição cancelou (e commitou) depois de a rota ter lido o lote.
        $probe->table('billing_batches')->where('id', $fx['batch_id'])->update(['status' => BillingBatchStatus::Cancelled->value]);

        expect(fn () => app(BillingService::class)->submitBatch($stale))
            ->toThrow(ValidationException::class);

        $probe->table('billing_batches')->where('id', $fx['batch_id'])->update(['status' => BillingBatchStatus::Draft->value]);

        expect(app(BillingService::class)->submitBatch(bclBatch($fx['batch_id']))->status)->toBe(BillingBatchStatus::Submitted);

        // Lock de sessão solto: a outra sessão consegue o advisory do lote.
        $key = bclEditKey($fx['batch_id']);

        expect((bool) $probe->selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok)->toBeTrue();

        $probe->select('select pg_advisory_unlock(?)', [$key]);
    });
});

describe('ordem dos comandos (DB::listen)', function (): void {
    it('cancelar lote TISS: advisory do lote → lote → guias por id → lote TISS → guias TISS; sem schedules nem numeração', function (): void {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        actingAsFinancialEntityUser($entity);

        $first = createBillableSchedule($entity);
        createBillableSchedule($entity, ['covenant_id' => $first->covenant_id]);

        $batch = app(BillingService::class)->createBatch([
            'covenant_id'         => $first->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H40.1',
        ]);

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        app(BillingAdjustmentService::class)->cancelBatch($batch->fresh(), BCL_REASON);

        $sql      = collect($log)->pluck('sql');
        $position = fn (Closure $match): int|false => $sql->search($match);

        $advisory   = $position(fn (string $q): bool => str_contains($q, 'pg_try_advisory_xact_lock'));
        $batchLock  = $position(fn (string $q): bool => str_contains($q, 'from "billing_batches"') && str_ends_with($q, 'for update'));
        $claimsLock = $position(fn (string $q): bool => str_contains($q, 'from "billing_claims"') && str_ends_with($q, 'for update'));
        $tissBatch  = $position(fn (string $q): bool => str_contains($q, 'from "tiss_batches"') && str_ends_with($q, 'for update'));
        $tissGuides = $position(fn (string $q): bool => str_contains($q, 'from "tiss_guides"') && str_ends_with($q, 'for update'));

        expect($advisory)->toBeInt()
            ->and($batchLock)->toBeInt()
            ->and($claimsLock)->toBeInt()
            ->and($tissBatch)->toBeInt()
            ->and($tissGuides)->toBeInt()
            ->and($advisory)->toBeLessThan($batchLock)
            ->and($batchLock)->toBeLessThan($claimsLock)
            ->and($claimsLock)->toBeLessThan($tissBatch)
            ->and($tissBatch)->toBeLessThan($tissGuides)
            ->and($sql[$claimsLock])->toContain('order by "id" asc')
            ->and($sql[$tissGuides])->toContain('order by "tiss_guides"."id" asc')
            // Nenhum lock/consulta de agendamento e nenhuma numeração (só o advisory do lote).
            ->and($sql->contains(fn (string $q): bool => str_contains($q, 'from "schedules"')))->toBeFalse()
            ->and(collect($log)->filter(fn (array $q): bool => str_contains($q['sql'], 'advisory'))->pluck('bindings.0')->unique()->values()->all())
            ->toBe([bclEditKey((string) $batch->id)]);
    });
});

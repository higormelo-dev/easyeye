<?php

declare(strict_types=1);

/**
 * Anexar guias ao lote e recebimento em massa × o resto do faturamento —
 * ordem de locks documentada em BillingService:
 *  - anexar: advisory dos lotes (só tenta) → billing_batches (destino e
 *    origens, por id) → billing_claims por id → tiss_batches → tiss_guides;
 *  - receber: billing_claims por id → CashPeriodLock compartilhado; nunca o lote.
 *
 * Contenção real, como em BillingCancelLockTest: uma SEGUNDA sessão
 * PostgreSQL (a "outra requisição") segura o lock com lock_timeout próprio e a
 * sessão do teste usa `set local lock_timeout = '300ms'` — 55P03 = ficou
 * esperando. Para a outra sessão enxergar as linhas, elas são gravadas e
 * COMMITADAS por ela (fora da transação do RefreshDatabase) e apagadas depois
 * do rollback do teste. A outra sessão sempre pega o lock ANTES de a sessão
 * do teste tocar nas linhas.
 */

use App\Domains\Tiss\Models\TissGuide;
use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\{BillingBatchAttachService, BillingBulkReceiptService, BillingEditLocks, BillingService, CashPeriodLock};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const BBL_PROBE = 'pgsql_lock_probe_billing_bulk';

function bblProbe(): Connection
{
    config(['database.connections.' . BBL_PROBE => config('database.connections.' . config('database.default'))]);

    return DB::connection(BBL_PROBE);
}

function bblRelease(Connection $probe): void
{
    while ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }
}

/**
 * Clínica + convênio + lote (com lote TISS aberto, se pedido) + guias,
 * gravados e COMMITADOS pela outra sessão. Guias em ordem crescente de id.
 * A limpeza roda depois do rollback do teste.
 *
 * @return array{entity_id: string, covenant_id: string, batch_id: string, claim_ids: list<string>, operator_id: string|null}
 */
function bblFixtures(
    Connection $probe,
    int $claims = 2,
    BillingBatchStatus $batchStatus = BillingBatchStatus::Submitted,
    BillingClaimStatus $claimStatus = BillingClaimStatus::Submitted,
    bool $tiss = false,
    bool $claimsInBatch = true,
): array {
    $now      = now();
    $suffix   = Str::upper(Str::random(8));
    $entityId = (string) Str::uuid();
    $covenant = (string) Str::uuid();
    $batchId  = (string) Str::uuid();
    $operator = $tiss ? (string) Str::uuid() : null;
    $tissId   = $tiss ? (string) Str::uuid() : null;

    $probe->table('entities')->insert(array_merge(Entity::factory()->raw(), [
        'id' => $entityId, 'code' => "ENT-B{$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]));

    $probe->table('covenants')->insert(array_merge(Covenant::factory()->raw(['entity_id' => $entityId]), [
        'id' => $covenant, 'code' => "CV-B{$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]));

    if ($tiss) {
        $probe->table('tiss_operators')->insert([
            'id'     => $operator, 'ans_code' => (string) random_int(100000000, 999999999), 'name' => "Operadora {$suffix}",
            'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $probe->table('tiss_batches')->insert([
            'id'              => $tissId, 'entity_id' => $entityId, 'operator_id' => $operator, 'batch_number' => "LOT-T{$suffix}",
            'reference_month' => $now->format('Y-m'), 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $probe->table('billing_batches')->insert([
        'id'            => $batchId,
        'entity_id'     => $entityId,
        'covenant_id'   => $covenant,
        'tiss_batch_id' => $tissId,
        'code'          => "LOT-B{$suffix}",
        'status'        => $batchStatus->value,
        'period_start'  => $now->toDateString(),
        'period_end'    => $now->toDateString(),
        'total_claims'  => $claims,
        'total_amount'  => 100 * $claims,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    $claimIds = collect(range(1, $claims))->map(fn () => (string) Str::uuid())->sort()->values()->all();

    foreach ($claimIds as $i => $claimId) {
        $probe->table('billing_claims')->insert([
            'id'              => $claimId,
            'entity_id'       => $entityId,
            'covenant_id'     => $covenant,
            'batch_id'        => $claimsInBatch ? $batchId : null,
            'code'            => "GUI-B{$suffix}-{$i}",
            'guide_number'    => "GUI-B{$suffix}-{$i}",
            'status'          => $claimStatus->value,
            'attendance_date' => $now->toDateString(),
            'amount'          => 100,
            'quantity'        => 1,
            'unit_price'      => 100,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    // beforeApplicationDestroyed é protegido: registra no escopo do caso de
    // teste. Roda depois do rollback do RefreshDatabase.
    (fn (callable $cleanup) => $this->beforeApplicationDestroyed($cleanup))->call(test(), fn () => bblCleanup($entityId, $operator));

    return ['entity_id' => $entityId, 'covenant_id' => $covenant, 'batch_id' => $batchId, 'claim_ids' => $claimIds, 'operator_id' => $operator];
}

function bblCleanup(string $entityId, ?string $operatorId): void
{
    $probe = bblProbe();
    bblRelease($probe);

    // Sem lock_timeout infinito: se algo ainda segurar as linhas, falha em vez de travar a suíte.
    $probe->statement("set lock_timeout = '5s'");
    $probe->table('financial_cash_entries')->where('entity_id', $entityId)->delete();
    $probe->table('billing_claims')->where('entity_id', $entityId)->delete();
    $probe->table('billing_batches')->where('entity_id', $entityId)->delete();
    $probe->table('tiss_batches')->where('entity_id', $entityId)->delete();
    $probe->table('covenants')->where('entity_id', $entityId)->delete();
    $probe->table('entities')->where('id', $entityId)->delete();

    if ($operatorId !== null) {
        $probe->table('tiss_operators')->where('id', $operatorId)->delete();
    }

    DB::purge(BBL_PROBE);
}

/** A outra sessão abre transação e trava a linha (lock_timeout próprio). */
function bblHoldRow(Connection $probe, string $table, string $id): void
{
    $probe->beginTransaction();
    $probe->statement("set local lock_timeout = '2s'");
    $probe->select("select id from {$table} where id = ? for update", [$id]);
}

/** A sessão do teste desiste de esperar lock depois de 300ms (erro 55P03). */
function bblShortLockTimeout(): void
{
    DB::statement("set local lock_timeout = '300ms'");
}

function bblExpectLockTimeout(Closure $action): void
{
    expect($action)->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
}

/** @param list<string> $claimIds */
function bblPay(string $entityId, array $claimIds): array
{
    return app(BillingBulkReceiptService::class)->payClaims(
        $entityId,
        collect($claimIds)->mapWithKeys(fn (string $id): array => [$id => 100])->all(),
        ['paid_at' => now()->toDateString(), 'payment_method' => 'transfer'],
    );
}

function bblCashKey(string $entityId): int
{
    return (new ReflectionMethod(CashPeriodLock::class, 'lockKey'))->invoke(null, $entityId);
}

describe('recebimento em massa: guias por id e depois o caixa; nunca o lote', function (): void {
    it('espera quem segura uma das guias (ex.: recebimento individual) e não grava nenhuma', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 3);

        try {
            // A do meio presa: a 1ª já foi travada pelo teste, mas o savepoint desfaz tudo.
            bblHoldRow($probe, 'billing_claims', $fx['claim_ids'][1]);
            bblShortLockTimeout();

            bblExpectLockTimeout(fn () => bblPay($fx['entity_id'], $fx['claim_ids']));
        } finally {
            bblRelease($probe);
        }

        expect(BillingClaim::query()->whereIn('id', $fx['claim_ids'])->where('status', BillingClaimStatus::Paid->value)->count())->toBe(0)
            ->and(FinancialCashEntry::query()->where('entity_id', $fx['entity_id'])->count())->toBe(0);

        // Solta a guia: paga as três, um lançamento por guia.
        expect(count(bblPay($fx['entity_id'], $fx['claim_ids'])['paid']))->toBe(3)
            ->and(FinancialCashEntry::query()->where('entity_id', $fx['entity_id'])->count())->toBe(3);
    });

    it('espera o fechamento de caixa em andamento (CashPeriodLock exclusivo) e não grava nenhuma', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 2);

        try {
            $probe->beginTransaction();
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock(?)', [bblCashKey($fx['entity_id'])]);
            bblShortLockTimeout();

            bblExpectLockTimeout(fn () => bblPay($fx['entity_id'], $fx['claim_ids']));
        } finally {
            bblRelease($probe);
        }

        expect(BillingClaim::query()->whereIn('id', $fx['claim_ids'])->where('status', BillingClaimStatus::Paid->value)->count())->toBe(0)
            ->and(FinancialCashEntry::query()->where('entity_id', $fx['entity_id'])->count())->toBe(0);
    });

    it('nunca pede o lote: com a outra sessão segurando a linha do lote (cancelar/anexar/enviar), paga na hora', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 2);

        try {
            bblHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bblShortLockTimeout();

            $preview = app(BillingBulkReceiptService::class)->batchPreview(BillingBatch::query()->findOrFail($fx['batch_id']));
            $result  = bblPay($fx['entity_id'], $fx['claim_ids']);
        } finally {
            bblRelease($probe);
        }

        expect($preview['count'])->toBe(2)
            ->and(count($result['paid']))->toBe(2);
    });

    it('ordem dos comandos: guias FOR UPDATE por id antes do lock do caixa; nenhum lock de lote', function (): void {
        $entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $covenant = Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true]);
        $ids      = collect(range(1, 3))->map(fn () => BillingClaim::query()->create([
            'entity_id'       => $entity->id, 'covenant_id' => $covenant->id, 'status' => BillingClaimStatus::Submitted->value,
            'attendance_date' => now()->toDateString(), 'amount' => 100, 'quantity' => 1, 'unit_price' => 100,
        ])->id)->all();

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = strtolower($query->sql);
        });

        bblPay((string) $entity->id, $ids);

        $sql        = collect($log);
        $claimsLock = $sql->search(fn (string $q): bool => str_contains($q, 'from "billing_claims"') && str_ends_with($q, 'for update'));
        $cashLock   = $sql->search(fn (string $q): bool => str_contains($q, 'pg_advisory_xact_lock_shared'));

        expect($claimsLock)->toBeInt()
            ->and($cashLock)->toBeInt()
            ->and($claimsLock)->toBeLessThan($cashLock)
            ->and($sql[$claimsLock])->toContain('order by "id" asc')
            ->and($sql->contains(fn (string $q): bool => str_contains($q, 'from "billing_batches"') && str_contains($q, 'for update')))->toBeFalse()
            ->and(FinancialCashEntry::query()->whereIn('billing_claim_id', $ids)->count())->toBe(3);
    });
});

describe('anexar guias: advisory → lotes → guias → lote TISS → guias TISS', function (): void {
    it('espera quem segura a linha do lote de destino (cancelar/enviar/baixar XML) e não mexe em nada', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 1, batchStatus: BillingBatchStatus::Draft, claimStatus: BillingClaimStatus::Draft, tiss: true, claimsInBatch: false);

        try {
            bblHoldRow($probe, 'billing_batches', $fx['batch_id']);
            bblShortLockTimeout();

            bblExpectLockTimeout(fn () => app(BillingBatchAttachService::class)->attachClaims(BillingBatch::query()->findOrFail($fx['batch_id']), $fx['claim_ids']));
        } finally {
            bblRelease($probe);
        }

        expect(BillingClaim::query()->findOrFail($fx['claim_ids'][0])->batch_id)->toBeNull();
    });

    it('espera quem segura a guia (receber/cancelar/corrigir a mesma guia) depois de travar o lote', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 1, batchStatus: BillingBatchStatus::Draft, claimStatus: BillingClaimStatus::Draft, tiss: true, claimsInBatch: false);

        try {
            bblHoldRow($probe, 'billing_claims', $fx['claim_ids'][0]);
            bblShortLockTimeout();

            bblExpectLockTimeout(fn () => app(BillingBatchAttachService::class)->attachClaims(BillingBatch::query()->findOrFail($fx['batch_id']), $fx['claim_ids']));
        } finally {
            bblRelease($probe);
        }

        expect(BillingClaim::query()->findOrFail($fx['claim_ids'][0])->batch_id)->toBeNull();
    });

    it('lote de destino ou de origem em envio (advisory de sessão na outra conexão): recusa na hora, sem esperar', function (): void {
        $probe = bblProbe();
        $fx    = bblFixtures($probe, claims: 1, batchStatus: BillingBatchStatus::Draft, claimStatus: BillingClaimStatus::Draft, tiss: true, claimsInBatch: false);
        $key   = app(BillingEditLocks::class)->key($fx['batch_id']);
        $batch = BillingBatch::query()->findOrFail($fx['batch_id']);

        try {
            $probe->statement("set lock_timeout = '2s'");
            $probe->select('select pg_advisory_lock(?)', [$key]);
            bblShortLockTimeout();

            expect(fn () => app(BillingBatchAttachService::class)->attachClaims($batch, $fx['claim_ids']))
                ->toThrow(fn (ValidationException $e) => expect($e->errors()['batch'][0])->toBe(__('financial_billing.errors.batch_busy', ['code' => $batch->code])));
            expect(fn () => app(BillingBatchAttachService::class)->reprocessPending($batch))
                ->toThrow(fn (ValidationException $e) => expect($e->errors()['batch'][0])->toBe(__('financial_billing.errors.batch_busy', ['code' => $batch->code])));
        } finally {
            $probe->select('select pg_advisory_unlock(?)', [$key]);
            bblRelease($probe);
        }

        // Origem em envio: a guia pendente de outro lote não sai dele enquanto ele é enviado.
        $source = BillingBatch::query()->create([
            'entity_id'    => $fx['entity_id'], 'covenant_id' => $fx['covenant_id'], 'status' => BillingBatchStatus::Draft->value,
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
        ]);
        BillingClaim::query()->whereKey($fx['claim_ids'][0])->update(['batch_id' => $source->id]);
        $sourceKey = app(BillingEditLocks::class)->key((string) $source->id);

        try {
            $probe->statement("set lock_timeout = '2s'");
            $probe->select('select pg_advisory_lock(?)', [$sourceKey]);

            expect(fn () => app(BillingBatchAttachService::class)->attachClaims($batch, $fx['claim_ids']))
                ->toThrow(fn (ValidationException $e) => expect($e->errors()['batch'][0])->toBe(__('financial_billing.errors.batch_busy', ['code' => $source->code])));
        } finally {
            $probe->select('select pg_advisory_unlock(?)', [$sourceKey]);
            bblRelease($probe);
        }
    });

    it('ordem dos comandos (DB::listen): advisory → lotes (destino e origem, por id) → guias → lote TISS → guias TISS; sem schedules nem numeração', function (): void {
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        actingAsFinancialEntityUser($entity);
        $service = app(BillingService::class);

        $s1      = createBillableSchedule($entity);
        $source  = $service->createBatch(['covenant_id' => $s1->covenant_id, 'date_from' => now()->subDay()->toDateString(), 'date_until' => now()->toDateString(), 'unit_price' => 150]);
        $pending = BillingClaim::query()->where('schedule_id', $s1->id)->firstOrFail();
        TissGuide::query()->whereKey($pending->tiss_guide_id)->update(['clinical_indication' => 'H40.1']);
        createBillableSchedule($entity, ['covenant_id' => $s1->covenant_id]); // vai para o lote de destino
        $target = $service->createBatch(['covenant_id' => $s1->covenant_id, 'date_from' => now()->subDay()->toDateString(), 'date_until' => now()->toDateString(), 'unit_price' => 150, 'clinical_indication' => 'H40.1']);
        $single = $service->createIndividual(['schedule_id' => createBillableSchedule($entity, ['covenant_id' => $s1->covenant_id])->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        $result = app(BillingBatchAttachService::class)->attachClaims($target->fresh(), [$pending->id, $single->id]);

        $sql      = collect($log)->pluck('sql');
        $position = fn (Closure $match): int|false => $sql->search($match);

        $advisory   = $position(fn (string $q): bool => str_contains($q, 'pg_try_advisory_xact_lock'));
        $batches    = $position(fn (string $q): bool => str_contains($q, 'from "billing_batches"') && str_ends_with($q, 'for update'));
        $claims     = $position(fn (string $q): bool => str_contains($q, 'from "billing_claims"') && str_ends_with($q, 'for update'));
        $tissBatch  = $position(fn (string $q): bool => str_contains($q, 'from "tiss_batches"') && str_ends_with($q, 'for update'));
        $tissGuides = $position(fn (string $q): bool => str_contains($q, 'from "tiss_guides"') && str_ends_with($q, 'for update'));

        expect(collect($result['results'])->where('attached', true)->count())->toBe(2)
            ->and($advisory)->toBeInt()->and($batches)->toBeInt()->and($claims)->toBeInt()->and($tissBatch)->toBeInt()->and($tissGuides)->toBeInt()
            ->and($advisory)->toBeLessThan($batches)
            ->and($batches)->toBeLessThan($claims)
            ->and($claims)->toBeLessThan($tissBatch)
            ->and($tissBatch)->toBeLessThan($tissGuides)
            ->and($sql[$batches])->toContain('order by "id" asc')
            ->and($sql[$claims])->toContain('order by "id" asc')
            ->and($sql[$tissGuides])->toContain('order by "tiss_guides"."id" asc')
            ->and($sql->contains(fn (string $q): bool => str_contains($q, 'for no key update')))->toBeFalse()
            // Só os advisory de edição do destino e da origem — nenhuma numeração.
            ->and(collect($log)->filter(fn (array $q): bool => str_contains($q['sql'], 'advisory'))->pluck('bindings.0')->unique()->sort()->values()->all())
            ->toBe(collect([(string) $source->id, (string) $target->id])->map(fn (string $id): int => app(BillingEditLocks::class)->key($id))->sort()->values()->all());
    });
});

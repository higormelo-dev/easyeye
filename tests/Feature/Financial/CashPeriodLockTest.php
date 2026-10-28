<?php

declare(strict_types=1);

/**
 * Lançamento de caixa × fechamento do período (CashPeriodLock).
 *
 * Antes: o lançamento checava "período fechado?" com um SELECT simples e o
 * fechamento somava o período sem esperar ninguém. Lançamento que passava na
 * checagem enquanto o fechamento somava ficava gravado DENTRO do período
 * fechado e fora dos totais do CashClose.
 *
 * A contenção real é reproduzida com uma SEGUNDA sessão PostgreSQL segurando o
 * lock e lock_timeout curto na sessão do teste (55P03 = esperou pelo lock) —
 * determinístico, sem depender de timing.
 */

use App\Enums\{BillingClaimStatus, FinancialEntryType};
use App\Models\{BillingClaim, CashClose, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\{BillingService, CashClosingService, CashFlowService, CashPeriodLock};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

const CPL_PROBE_CONNECTION = 'pgsql_lock_probe_cash_period';

function cplLockKey(string $entityId): int
{
    return (new ReflectionMethod(CashPeriodLock::class, 'lockKey'))->invoke(null, $entityId);
}

/** Segunda sessão PostgreSQL (a "outra requisição"), com transação aberta. Fechar com cplCloseProbe(). */
function cplProbe(): Connection
{
    config(['database.connections.' . CPL_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(CPL_PROBE_CONNECTION);
    $probe->beginTransaction();

    return $probe;
}

function cplCloseProbe(Connection $probe): void
{
    if ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }

    DB::purge(CPL_PROBE_CONNECTION);
}

/** Outra sessão fechando o caixa (exclusivo) ou lançando (compartilhado) nesta clínica. */
function cplHoldLock(Connection $probe, Entity $entity, bool $exclusive): void
{
    // Se a própria sessão do teste já segura o lock, falha rápido em vez de travar a suíte.
    $probe->statement("set local lock_timeout = '2s'");

    $probe->select(
        $exclusive ? 'select pg_advisory_xact_lock(?)' : 'select pg_advisory_xact_lock_shared(?)',
        [cplLockKey((string) $entity->id)],
    );
}

/** A sessão do teste desiste de esperar lock depois de 300ms (erro 55P03). */
function cplShortLockTimeout(): void
{
    DB::statement("set local lock_timeout = '300ms'");
}

function cplExpectLockTimeout(Closure $action): void
{
    expect($action)->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
}

function cplEntry(array $overrides = []): FinancialCashEntry
{
    return app(CashFlowService::class)->create(array_merge([
        'entry_date'  => '2026-06-10',
        'description' => 'Consulta',
        'type'        => FinancialEntryType::Income->value,
        'amount'      => 100,
    ], $overrides));
}

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    session(['selected_entity_id' => $this->entity->id]);
});

describe('lançamento espera o fechamento em andamento', function (): void {
    it('novo lançamento espera o lock exclusivo de quem está fechando — e depois grava normalmente', function (): void {
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: true);
            cplShortLockTimeout();

            cplExpectLockTimeout(fn () => cplEntry());
        } finally {
            cplCloseProbe($probe);
        }

        // A tentativa que esperou não gravou nada; solto o lock, grava.
        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(0)
            ->and(cplEntry()->exists)->toBeTrue();
    });

    it('editar e excluir lançamento também esperam o fechamento', function (): void {
        // Direto no model: pelo service, a sessão do teste já ficaria com o lock
        // compartilhado até o fim da transação do teste e o fechamento rival
        // (exclusivo) nunca conseguiria o lock — que é justamente o esperado.
        $entry = FinancialCashEntry::query()->create([
            'entity_id'   => $this->entity->id,
            'entry_date'  => '2026-06-10',
            'description' => 'Consulta',
            'type'        => FinancialEntryType::Income->value,
            'amount'      => 100,
            'active'      => true,
        ]);
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: true);
            cplShortLockTimeout();

            cplExpectLockTimeout(fn () => app(CashFlowService::class)->update($entry, ['amount' => 150]));
            cplExpectLockTimeout(fn () => app(CashFlowService::class)->delete($entry));
        } finally {
            cplCloseProbe($probe);
        }

        expect((string) $entry->fresh()->amount)->toBe('100.00')
            ->and($entry->fresh()->trashed())->toBeFalse();
    });

    it('registrar recebimento de guia também espera o fechamento (lançamento automático)', function (): void {
        $claim = BillingClaim::query()->create([
            'entity_id'       => $this->entity->id,
            'covenant_id'     => Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true])->id,
            'status'          => BillingClaimStatus::Submitted->value,
            'attendance_date' => now()->toDateString(),
            'amount'          => 250,
            'quantity'        => 1,
            'unit_price'      => 250,
        ]);
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: true);
            cplShortLockTimeout();

            cplExpectLockTimeout(fn () => app(BillingService::class)->markClaimPaid($claim));
        } finally {
            cplCloseProbe($probe);
        }

        expect($claim->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->count())->toBe(0);
    });

    it('a checagem do período roda DEPOIS do lock — comando novo, que enxerga o fechamento de quem segurava', function (): void {
        cplEntry(); // aquece

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = strtolower($query->sql);
        });

        cplEntry();

        $entries  = collect($log);
        $lockAt   = $entries->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock_shared'));
        $checkAt  = $entries->search(fn (string $sql): bool => str_contains($sql, 'from "cash_closes"'));
        $insertAt = $entries->search(fn (string $sql): bool => str_starts_with($sql, 'insert into "financial_cash_entries"'));

        expect($lockAt)->toBeInt()
            ->and($checkAt)->toBeInt()
            ->and($insertAt)->toBeInt()
            ->and($lockAt)->toBeLessThan($checkAt)
            ->and($checkAt)->toBeLessThan($insertAt);
    });

    it('o lock de escrita fica com quem lançou até o COMMIT (fechamento não passa no meio)', function (): void {
        cplEntry();

        $probe = cplProbe();

        try {
            $key = cplLockKey((string) $this->entity->id);

            expect((bool) $probe->selectOne('select pg_try_advisory_xact_lock(?) as ok', [$key])->ok)->toBeFalse()
                // Outro lançamento (compartilhado) segue livre.
                ->and((bool) $probe->selectOne('select pg_try_advisory_xact_lock_shared(?) as ok', [$key])->ok)->toBeTrue();
        } finally {
            cplCloseProbe($probe);
        }
    });

    it('lançamentos não se bloqueiam entre si (lock compartilhado)', function (): void {
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: false);
            cplShortLockTimeout();

            expect(cplEntry()->exists)->toBeTrue();
        } finally {
            cplCloseProbe($probe);
        }
    });
});

describe('fechamento espera os lançamentos em andamento', function (): void {
    it('fechar o período espera quem está lançando e não grava fechamento pela metade', function (): void {
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: false);
            cplShortLockTimeout();

            cplExpectLockTimeout(fn () => app(CashClosingService::class)->closePeriod((string) $this->entity->id, '2026-06-01', '2026-06-30'));
        } finally {
            cplCloseProbe($probe);
        }

        expect(CashClose::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('pega o lock antes de checar sobreposição e somar, e não trava mais a linha da clínica (FKs livres)', function (): void {
        cplEntry();

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = strtolower($query->sql);
        });

        $close = app(CashClosingService::class)->closePeriod((string) $this->entity->id, '2026-06-01', '2026-06-30');

        $entries = collect($log);
        $lockAt  = $entries->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock(') && ! str_contains($sql, 'shared'));
        $sumAt   = $entries->search(fn (string $sql): bool => str_contains($sql, 'from "financial_cash_entries"'));
        $checkAt = $entries->search(fn (string $sql): bool => str_contains($sql, 'from "cash_closes"'));

        expect((float) $close->total_income)->toBe(100.0)
            ->and($lockAt)->toBeInt()
            ->and($lockAt)->toBeLessThan($checkAt)
            ->and($lockAt)->toBeLessThan($sumAt)
            ->and($entries->contains(fn (string $sql): bool => str_contains($sql, 'from "entities"') && str_contains($sql, 'for update')))->toBeFalse();
    });

    it('o lock de uma clínica não bloqueia o caixa de outra', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $probe = cplProbe();

        try {
            cplHoldLock($probe, $this->entity, exclusive: true);
            cplShortLockTimeout();

            $close = app(CashClosingService::class)->closePeriod((string) $other->id, '2026-06-01', '2026-06-30');

            session(['selected_entity_id' => $other->id]);
            $entry = cplEntry(['entry_date' => '2026-07-10']);
        } finally {
            cplCloseProbe($probe);
        }

        expect($close->exists)->toBeTrue()
            ->and($entry->entity_id)->toBe($other->id);
    });
});

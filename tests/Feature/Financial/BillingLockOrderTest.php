<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Entity, Schedule};
use App\Services\Financial\BillingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ordem GLOBAL de locks do faturamento (lote x individual da mesma clínica).
 *
 * Antes: o individual travava o agendamento (FOR UPDATE) e só depois pedia a
 * numeração GUI (advisory até o commit); o lote pegava a numeração GUI no
 * 1º agendamento e, nos seguintes, o INSERT da guia pedia FOR KEY SHARE (FK)
 * na linha do agendamento que o individual segurava => ciclo => PostgreSQL
 * 40P01 (deadlock) => HTTP 500 e lote inteiro revertido. O provisionamento
 * TISS do convênio também invertia: o lote provisionava antes da numeração,
 * o individual depois.
 *
 * Ordem única, nos DOIS fluxos:
 *   1. linhas de `schedules` (FOR NO KEY UPDATE, por id crescente);
 *   2. provisionamento TISS do convênio (operadora/credencial/contrato);
 *   3. numeração (advisory): LOT (lote) → LOT-AAAAMM (lote TISS) → GUI (guia)
 *      → GUI-AAAAMM (guia TISS).
 *
 * A prova com processos paralelos reais (lote x individuais, várias rodadas)
 * fica fora da suíte; aqui fica a ordem, determinística, via DB::listen.
 */
const BLO_PROVISIONING = [
    'insert into "tiss_operators"',
    'insert into "tiss_entity_operator_credentials"',
    'insert into "tiss_entity_operator_contracts"',
    'update "covenants"',
];

/**
 * Executa $run gravando cada statement (minúsculo) e os bindings.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function bloRecord(Closure $run): array
{
    $log       = [];
    $recording = true;

    DB::listen(function (QueryExecuted $query) use (&$log, &$recording): void {
        if ($recording) {
            $log[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        }
    });

    try {
        $run();
    } finally {
        $recording = false;
    }

    return $log;
}

function bloIsScheduleLock(string $sql): bool
{
    return str_contains($sql, 'from "schedules"') && preg_match('/ for (no key )?update$/', $sql) === 1;
}

function bloIsAdvisory(string $sql): bool
{
    return str_contains($sql, 'pg_advisory_xact_lock');
}

function bloIsProvisioning(string $sql): bool
{
    foreach (BLO_PROVISIONING as $prefix) {
        if (str_starts_with($sql, $prefix)) {
            return true;
        }
    }

    return false;
}

/** Mesma chave de HasEntityCode / Create*TissAction (60 bits do sha1). */
function bloKey(string ...$parts): int
{
    return (int) hexdec(substr(sha1(implode('|', $parts)), 0, 15));
}

/**
 * Chaves advisory na ordem da PRIMEIRA aquisição.
 *
 * @param list<array{sql: string, bindings: array<int, mixed>}> $log
 *
 * @return list<int>
 */
function bloAdvisoryKeys(array $log): array
{
    return collect($log)
        ->filter(fn (array $q): bool => bloIsAdvisory($q['sql']))
        ->map(fn (array $q): int => (int) $q['bindings'][0])
        ->unique()
        ->values()
        ->all();
}

/**
 * @param list<array{sql: string, bindings: array<int, mixed>}> $log
 */
function bloAssertGlobalOrder(array $log): void
{
    $sql          = array_column($log, 'sql');
    $firstLock    = collect($sql)->search(fn (string $q): bool => bloIsScheduleLock($q));
    $firstNumber  = collect($sql)->search(fn (string $q): bool => bloIsAdvisory($q));
    $lastLock     = collect($sql)->reverse()->search(fn (string $q): bool => bloIsScheduleLock($q));
    $provisioning = collect($sql)->filter(fn (string $q): bool => bloIsProvisioning($q))->keys();

    expect($firstLock)->toBeInt('nenhum lock de linha em schedules')
        ->and($firstNumber)->toBeInt('nenhum advisory de numeração')
        // 1º as linhas dos agendamentos; nenhuma linha de agendamento travada depois da numeração.
        ->and($firstLock)->toBeLessThan($firstNumber)
        ->and($lastLock)->toBeLessThan($firstNumber)
        // Lock que não bloqueia o FOR KEY SHARE das FKs (só billing x billing e UPDATE/DELETE do agendamento).
        ->and($sql[$firstLock])->toEndWith('for no key update')
        // O provisionamento TISS acontece entre as linhas e a numeração.
        ->and($provisioning->isNotEmpty())->toBeTrue('provisionamento TISS não exercitado')
        ->and($provisioning->min())->toBeGreaterThan($firstLock)
        ->and($provisioning->max())->toBeLessThan($firstNumber);
}

function bloBatchPayload(Schedule $schedule): array
{
    return [
        'covenant_id'         => $schedule->covenant_id,
        'date_from'           => now()->subDay()->toDateString(),
        'date_until'          => now()->toDateString(),
        'unit_price'          => 150,
        'tuss_code'           => '10101012',
        'clinical_indication' => 'H52.1',
    ];
}

/** Guia ativa gravada "por fora" (outra transação que venceu a corrida). */
function bloForeignActiveClaim(Schedule $schedule): void
{
    DB::table('billing_claims')->insert([
        'id'              => (string) Str::uuid(),
        'entity_id'       => $schedule->entity_id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $schedule->covenant_id,
        'code'            => 'EXT-' . Str::upper(Str::random(8)),
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 1,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);

    $this->first     = createBillableSchedule($this->entity);
    $this->schedules = collect([$this->first])->merge(
        collect([2, 3])->map(fn (int $hours): Schedule => createBillableSchedule($this->entity, [
            'covenant_id' => $this->first->covenant_id,
            'date_time'   => now()->subHours($hours),
        ])),
    );
});

describe('createBatch', function (): void {
    it('trava os agendamentos (por id) antes do provisionamento TISS e de qualquer numeração', function (): void {
        $log = bloRecord(fn () => app(BillingService::class)->createBatch(bloBatchPayload($this->first)));

        bloAssertGlobalOrder($log);

        $lock = collect($log)->first(fn (array $q): bool => bloIsScheduleLock($q['sql']))['sql'];

        expect($lock)->toContain('order by "schedules"."id" asc')
            ->and(BillingClaim::query()->whereIn('schedule_id', $this->schedules->pluck('id'))->count())->toBe(3);
    });

    it('pega a numeração na ordem global: LOT → LOT-AAAAMM (TISS) → GUI → GUI-AAAAMM (TISS)', function (): void {
        $log = bloRecord(fn () => app(BillingService::class)->createBatch(bloBatchPayload($this->first)));

        $entityId   = (string) $this->entity->id;
        $operatorId = (string) $this->first->covenant->fresh()->tiss_operator_id;
        $month      = now()->format('Ym');

        expect(bloAdvisoryKeys($log))->toBe([
            bloKey('entity_code', 'billing_batches', $entityId, 'LOT'),
            bloKey('tiss_batch_number', $entityId, $operatorId, "LOT-{$month}-"),
            bloKey('entity_code', 'billing_claims', $entityId, 'GUI'),
            bloKey('tiss_guide_number', $entityId, $operatorId, "GUI-{$month}-"),
        ]);
    });

    it('relê a elegibilidade depois do lock: agendamento faturado por outro enquanto esperava fica fora (sem 23505)', function (): void {
        $billedMeanwhile = $this->schedules[1];
        $done            = false;

        // Simula quem segurava a linha: grava a guia e commita antes de o lote obter o lock.
        DB::listen(function (QueryExecuted $query) use ($billedMeanwhile, &$done): void {
            if (! $done && bloIsScheduleLock(strtolower($query->sql))) {
                $done = true;
                bloForeignActiveClaim($billedMeanwhile);
            }
        });

        $batch = app(BillingService::class)->createBatch(bloBatchPayload($this->first));

        expect($done)->toBeTrue()
            ->and($batch->total_claims)->toBe(2)
            ->and($batch->notes)->toBeNull()
            ->and(BillingClaim::query()->where('batch_id', $batch->id)->pluck('schedule_id')->all())
            ->not->toContain($billedMeanwhile->id);
    });

    it('sem agendamento elegível não cria lote nem consome numeração', function (): void {
        $this->schedules->each(fn (Schedule $schedule) => bloForeignActiveClaim($schedule));

        $log = bloRecord(function (): void {
            expect(fn () => app(BillingService::class)->createBatch(bloBatchPayload($this->first)))
                ->toThrow(ValidationException::class);
        });

        expect(collect($log)->contains(fn (array $q): bool => bloIsAdvisory($q['sql'])))->toBeFalse();
    });
});

describe('createIndividual', function (): void {
    it('trava o agendamento antes do provisionamento TISS e da numeração', function (): void {
        $log = bloRecord(fn () => app(BillingService::class)->createIndividual([
            'schedule_id'         => $this->first->id,
            'unit_price'          => 150,
            'clinical_indication' => 'H52.1',
        ]));

        bloAssertGlobalOrder($log);

        $entityId   = (string) $this->entity->id;
        $operatorId = (string) $this->first->covenant->fresh()->tiss_operator_id;

        expect(bloAdvisoryKeys($log))->toBe([
            bloKey('entity_code', 'billing_claims', $entityId, 'GUI'),
            bloKey('tiss_guide_number', $entityId, $operatorId, 'GUI-' . now()->format('Ym') . '-'),
        ]);
    });

    it('guia ativa gravada por fora do lock (índice único parcial) vira erro de validação, não 500', function (): void {
        $done = false;

        // Depois do "já faturado?" e antes do INSERT: só o índice único parcial pega.
        DB::listen(function (QueryExecuted $query) use (&$done): void {
            $sql = strtolower($query->sql);

            if (! $done && str_contains($sql, 'from "billing_claims"') && str_contains($sql, '"schedule_id" =')) {
                $done = true;
                bloForeignActiveClaim($this->first);
            }
        });

        expect(fn () => app(BillingService::class)->createIndividual([
            'schedule_id'         => $this->first->id,
            'unit_price'          => 150,
            'clinical_indication' => 'H52.1',
        ]))->toThrow(function (ValidationException $e): void {
            expect($e->errors())->toHaveKey('schedule_id')
                ->and($e->errors()['schedule_id'][0])->toBe(__('financial_billing.errors.schedule_already_billed'));
        });

        expect($done)->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\{CreateTissBatchAction, CreateTissGuideAction};
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissOperator};
use App\Models\{BillingClaim, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Numeração TISS do prestador: guia GUI-AAAAMM-NNNNNN e lote LOT-AAAAMM-NNNN,
 * sequenciais por clínica × operadora × mês. Antes: último número lido sem
 * lock, sem enxergar registros excluídos (soft delete) e em ordem de texto =>
 * dois faturamentos em paralelo (ou um lote longo + um individual) calculavam
 * o mesmo número e o perdedor estourava o índice único => HTTP 500 e o lote
 * inteiro revertido.
 *
 * Corrida real (2 processos) fica fora da suíte; a colisão é reproduzida de
 * forma determinística trocando o número calculado por um já gravado.
 */
function tncOperator(): TissOperator
{
    return TissOperator::query()->create([
        'ans_code' => (string) random_int(100000, 999999),
        'name'     => 'Operadora ' . Str::random(4),
        'active'   => true,
    ]);
}

function tncGuidePrefix(): string
{
    return 'GUI-' . now()->format('Ym') . '-';
}

function tncBatchPrefix(): string
{
    return 'LOT-' . now()->format('Ym') . '-';
}

/** @param array<string, mixed> $payload */
function tncCreateGuide(Entity $entity, TissOperator $operator, array $payload = []): TissGuide
{
    return app(CreateTissGuideAction::class)(array_merge([
        'entity_id'       => $entity->id,
        'operator_id'     => $operator->id,
        'attendance_date' => now()->toDateString(),
    ], $payload));
}

/** @param array<string, mixed> $payload */
function tncCreateBatch(Entity $entity, TissOperator $operator, array $payload = []): TissBatch
{
    return app(CreateTissBatchAction::class)(array_merge([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
    ], $payload));
}

/**
 * Faz as $times primeiras tentativas de INSERT gravarem $takenNumber (número
 * que outro processo já gravou entre a leitura e o INSERT).
 *
 * @param class-string<TissGuide|TissBatch> $modelClass
 */
function tncStaleNumber(string $modelClass, string $column, string $takenNumber, int $times = 1): stdClass
{
    new $modelClass();

    $state = (object) ['attempts' => 0];

    $modelClass::creating(function ($model) use ($column, $takenNumber, $times, $state): void {
        $state->attempts++;

        if ($state->attempts <= $times) {
            $model->setAttribute($column, $takenNumber);
        }
    });

    return $state;
}

function tncAdvisoryLocksHeld(): int
{
    return (int) DB::selectOne(
        "select count(*) as total from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and granted",
    )->total;
}

beforeEach(function (): void {
    $this->freezeTime();
    seedActiveTissVersion();

    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->operator = tncOperator();
    session(['selected_entity_id' => $this->entity->id]);
});

describe('número da guia TISS (GUI-AAAAMM-NNNNNN)', function (): void {
    it('conta guias excluídas (soft delete): o índice único ainda enxerga o número', function (): void {
        tncCreateGuide($this->entity, $this->operator)->delete();

        expect(tncCreateGuide($this->entity, $this->operator)->guide_number_provider)->toBe(tncGuidePrefix() . '000002');
    });

    it('continua numérica depois de 999999 (a ordem de texto repetia 1000000)', function (): void {
        tncCreateGuide($this->entity, $this->operator, ['guide_number_provider' => tncGuidePrefix() . '999999']);
        tncCreateGuide($this->entity, $this->operator, ['guide_number_provider' => tncGuidePrefix() . '1000000']);

        expect(tncCreateGuide($this->entity, $this->operator)->guide_number_provider)->toBe(tncGuidePrefix() . '1000001');
    });

    it('ignora números fora do padrão no mesmo mês sem sair da sequência', function (): void {
        tncCreateGuide($this->entity, $this->operator, ['guide_number_provider' => tncGuidePrefix() . '000001']);
        tncCreateGuide($this->entity, $this->operator, ['guide_number_provider' => tncGuidePrefix() . 'LEGADO']);

        expect(tncCreateGuide($this->entity, $this->operator)->guide_number_provider)->toBe(tncGuidePrefix() . '000002');
    });

    it('é isolada por clínica e por operadora', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        tncCreateGuide($other, $this->operator);
        tncCreateGuide($this->entity, tncOperator());

        expect(tncCreateGuide($this->entity, $this->operator)->guide_number_provider)->toBe(tncGuidePrefix() . '000001');
    });

    it('colisão com número gravado por outro processo conclui com o próximo, dentro da transação do chamador', function (): void {
        $first = tncCreateGuide($this->entity, $this->operator);
        $state = tncStaleNumber(TissGuide::class, 'guide_number_provider', $first->guide_number_provider);

        $guide = DB::transaction(fn () => tncCreateGuide($this->entity, $this->operator));

        expect($guide->guide_number_provider)->toBe(tncGuidePrefix() . '000002')
            ->and($state->attempts)->toBe(2)
            ->and(TissGuide::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('colisão persistente desiste após tentativas limitadas sem envenenar a transação do chamador', function (): void {
        $first = tncCreateGuide($this->entity, $this->operator);
        $state = tncStaleNumber(TissGuide::class, 'guide_number_provider', $first->guide_number_provider, PHP_INT_MAX);

        DB::transaction(function () use ($state): void {
            expect(fn () => tncCreateGuide($this->entity, $this->operator))->toThrow(UniqueConstraintViolationException::class);

            expect(TissGuide::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1)
                ->and($state->attempts)->toBe(3);
        });
    });

    it('número informado explicitamente que colide não é trocado por outro', function (): void {
        $first = tncCreateGuide($this->entity, $this->operator);

        expect(fn () => DB::transaction(fn () => tncCreateGuide($this->entity, $this->operator, [
            'guide_number_provider' => $first->guide_number_provider,
        ])))->toThrow(UniqueConstraintViolationException::class);
    });

    it('segura um advisory lock de transação até o commit do chamador', function (): void {
        DB::transaction(function (): void {
            $before = tncAdvisoryLocksHeld();

            tncCreateGuide($this->entity, $this->operator);

            expect(tncAdvisoryLocksHeld())->toBeGreaterThan($before);
        });
    });
});

describe('número do lote TISS (LOT-AAAAMM-NNNN)', function (): void {
    it('conta lotes excluídos (soft delete)', function (): void {
        tncCreateBatch($this->entity, $this->operator)->delete();

        expect(tncCreateBatch($this->entity, $this->operator)->batch_number)->toBe(tncBatchPrefix() . '0002');
    });

    it('continua numérico depois de 9999', function (): void {
        tncCreateBatch($this->entity, $this->operator, ['batch_number' => tncBatchPrefix() . '9999']);
        tncCreateBatch($this->entity, $this->operator, ['batch_number' => tncBatchPrefix() . '10000']);

        expect(tncCreateBatch($this->entity, $this->operator)->batch_number)->toBe(tncBatchPrefix() . '10001');
    });

    it('usa o mês de referência do lote no prefixo e na sequência', function (): void {
        $previous = now()->subMonthNoOverflow()->format('Y-m');
        tncCreateBatch($this->entity, $this->operator, ['reference_month' => $previous]);

        expect(tncCreateBatch($this->entity, $this->operator, ['reference_month' => $previous])->batch_number)
            ->toBe('LOT-' . str_replace('-', '', $previous) . '-0002')
            ->and(tncCreateBatch($this->entity, $this->operator)->batch_number)->toBe(tncBatchPrefix() . '0001');
    });

    it('colisão com número gravado por outro processo conclui com o próximo', function (): void {
        $first = tncCreateBatch($this->entity, $this->operator);
        tncStaleNumber(TissBatch::class, 'batch_number', $first->batch_number);

        expect(DB::transaction(fn () => tncCreateBatch($this->entity, $this->operator))->batch_number)
            ->toBe(tncBatchPrefix() . '0002');
    });
});

describe('faturamento (BillingService) com numeração concorrente', function (): void {
    it('faturamento individual conclui quando a guia TISS colide com outra gravada em paralelo', function (): void {
        actingAsFinancialEntityUser($this->entity);
        $service = app(BillingService::class);

        $first  = createBillableSchedule($this->entity);
        $second = createBillableSchedule($this->entity, ['covenant_id' => $first->covenant_id]);

        $firstClaim = $service->createIndividual(['schedule_id' => $first->id, 'unit_price' => 150, 'clinical_indication' => 'H52.1']);
        $taken      = $firstClaim->tissGuide->guide_number_provider;

        tncStaleNumber(TissGuide::class, 'guide_number_provider', $taken);

        $claim = $service->createIndividual(['schedule_id' => $second->id, 'unit_price' => 150, 'clinical_indication' => 'H52.1']);

        expect($claim->tissGuide->guide_number_provider)->toBe(tncGuidePrefix() . '000002')
            ->and($claim->tissGuide->operator_id)->toBe($firstClaim->tissGuide->operator_id)
            ->and(BillingClaim::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });
});

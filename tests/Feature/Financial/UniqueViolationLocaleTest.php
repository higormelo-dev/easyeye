<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\{CreateTissBatchAction, CreateTissGuideAction};
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissOperator};
use App\Models\{BillingClaim, Covenant, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detectores de "qual índice único colidiu" (numeração GUI/LOT da clínica,
 * guia/lote TISS, guia ativa por agendamento) não podem depender do texto em
 * inglês do PostgreSQL nem de texto em qualquer lugar da mensagem (SQL e
 * bindings). Antes: com lc_messages=pt_BR a colisão do código da guia virava
 * HTTP 500 em vez de nova tentativa (HasEntityCode procurava "unique
 * constraint"); e as ações TISS tratavam como colisão de número qualquer
 * violação cujo SQL/bindings contivesse o nome do índice.
 *
 * O servidor de teste roda com lc_messages=C: a mensagem pt_BR é simulada com
 * o formato exato do catálogo pt_BR do PostgreSQL, lançada DENTRO do INSERT
 * (evento creating), no mesmo ponto em que a violação real acontece.
 */
function uvlPtBrUnique(string $table, string $constraint, array $bindings = ['x']): UniqueConstraintViolationException
{
    $driverMessage = "ERRO:  duplicar valor da chave viola a restrição de unicidade \"{$constraint}\"\n"
        . 'DETALHE:  Chave (entity_id, code)=(01a0, X-0000000001) já existe.';

    $pdo = new class($driverMessage) extends PDOException {
        public function __construct(string $driverMessage)
        {
            parent::__construct('SQLSTATE[23505]: Unique violation: 7 ' . $driverMessage);

            $this->code      = '23505';
            $this->errorInfo = ['23505', 7, $driverMessage];
        }
    };

    return new UniqueConstraintViolationException('pgsql', "insert into \"{$table}\" values (?)", $bindings, $pdo);
}

/**
 * Faz o INSERT de $modelClass falhar nas $times primeiras tentativas com a
 * exceção de $factory. Devolve o contador de tentativas.
 *
 * @param class-string<Model> $modelClass
 */
function uvlFailInsert(string $modelClass, Closure $factory, int $times = 1): stdClass
{
    // Boota o model ANTES: os listeners do próprio model (que geram o número)
    // rodam primeiro, como numa colisão real.
    new $modelClass();

    $state = (object) ['attempts' => 0];

    $modelClass::creating(function () use ($state, $factory, $times): void {
        if (++$state->attempts <= $times) {
            throw $factory();
        }
    });

    return $state;
}

function uvlOperator(): TissOperator
{
    return TissOperator::query()->create([
        'ans_code' => (string) random_int(100000, 999999),
        'name'     => 'Operadora ' . Str::random(4),
        'active'   => true,
    ]);
}

beforeEach(function (): void {
    seedActiveTissVersion();

    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
});

describe('HasEntityCode (numeração GUI-/LOT-/FLC-... da clínica)', function (): void {
    $claim = fn (Entity $entity, Covenant $covenant): BillingClaim => BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100,
    ]);

    it('refaz o código quando a colisão vem com a mensagem do servidor em pt_BR (antes: 500)', function () use ($claim): void {
        Log::spy();
        $state = uvlFailInsert(BillingClaim::class, fn () => uvlPtBrUnique('billing_claims', 'billing_claims_entity_code_unique'));

        $created = $claim($this->entity, $this->covenant);

        expect($state->attempts)->toBe(2)
            ->and($created->code)->toBe('GUI-0000000001')
            ->and($created->guide_number)->toBe($created->code);

        Log::shouldHaveReceived('warning')->with('entity_code.collision', Mockery::type('array'))->once();
    });

    it('não refaz quando a violação pt_BR é de OUTRO índice único da tabela', function () use ($claim): void {
        $state = uvlFailInsert(BillingClaim::class, fn () => uvlPtBrUnique('billing_claims', 'billing_claims_active_schedule_unique'));

        expect(fn () => $claim($this->entity, $this->covenant))
            ->toThrow(UniqueConstraintViolationException::class);

        expect($state->attempts)->toBe(1);
    });
});

describe('numeração TISS (guia GUI-AAAAMM / lote LOT-AAAAMM)', function (): void {
    $guide = fn (Entity $entity, TissOperator $operator): TissGuide => app(CreateTissGuideAction::class)([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
    ]);

    $batch = fn (Entity $entity, TissOperator $operator): TissBatch => app(CreateTissBatchAction::class)([
        'entity_id'   => $entity->id,
        'operator_id' => $operator->id,
    ]);

    it('guia: colisão do número com mensagem pt_BR => nova tentativa', function () use ($guide): void {
        $state = uvlFailInsert(TissGuide::class, fn () => uvlPtBrUnique('tiss_guides', 'tiss_guides_entity_operator_provider_number_unique'));

        expect($guide($this->entity, uvlOperator())->guide_number_provider)->toEndWith('-000001')
            ->and($state->attempts)->toBe(2);
    });

    it('guia: nome do índice só no SQL/bindings não é colisão de número (antes: 3 tentativas)', function () use ($guide): void {
        $state = uvlFailInsert(TissGuide::class, fn () => uvlPtBrUnique(
            'tiss_guides',
            'tiss_guides_pkey',
            ['beneficiary_name' => 'tiss_guides_entity_operator_provider_number_unique'],
        ), PHP_INT_MAX);

        expect(fn () => $guide($this->entity, uvlOperator()))->toThrow(UniqueConstraintViolationException::class);
        expect($state->attempts)->toBe(1);
    });

    it('lote: colisão do número com mensagem pt_BR => nova tentativa', function () use ($batch): void {
        $state = uvlFailInsert(TissBatch::class, fn () => uvlPtBrUnique('tiss_batches', 'tiss_batches_entity_operator_batch_unique'));

        expect($batch($this->entity, uvlOperator())->batch_number)->toEndWith('-0001')
            ->and($state->attempts)->toBe(2);
    });

    it('lote: nome do índice só no SQL/bindings não é colisão de número (antes: 3 tentativas)', function () use ($batch): void {
        $state = uvlFailInsert(TissBatch::class, fn () => uvlPtBrUnique(
            'tiss_batches',
            'tiss_batches_pkey',
            ['metadata' => 'tiss_batches_entity_operator_batch_unique'],
        ), PHP_INT_MAX);

        expect(fn () => $batch($this->entity, uvlOperator()))->toThrow(UniqueConstraintViolationException::class);
        expect($state->attempts)->toBe(1);
    });
});

describe('BillingService: guia ativa duplicada por agendamento', function (): void {
    it('lote: violação pt_BR do índice de guia ativa vira pendência, não 500', function (): void {
        actingAsFinancialEntityUser($this->entity);
        $first  = createBillableSchedule($this->entity);
        $second = createBillableSchedule($this->entity, ['covenant_id' => $first->covenant_id, 'date_time' => now()->subHours(2)]);

        uvlFailInsert(BillingClaim::class, fn () => uvlPtBrUnique('billing_claims', 'billing_claims_active_schedule_unique'));

        $batch = app(BillingService::class)->createBatch([
            'covenant_id'         => $first->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H52.1',
        ]);

        expect($batch->total_claims)->toBe(2)
            ->and(BillingClaim::query()->where('batch_id', $batch->id)->count())->toBe(1)
            ->and($batch->notes)->toContain('pendência')
            ->and($second->exists)->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\TissBatch;
use App\Enums\{BillingClaimStatus, PaymentMethod};
use App\Models\{BillingClaim, Entity, EntityProduct, FinancialCashEntry, ProductCategory};
use App\Services\Financial\{BillingService, CashFlowService};
use App\Services\Stock\ProductImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * App\Concerns\HasEntityCode — código sequencial por clínica (FLC-, GUI-,
 * LOT-, PRD-, ...). Antes: último código lido sem lock + nova tentativa na
 * MESMA transação. No PostgreSQL o INSERT que colide aborta a transação
 * (25P02), então a nova tentativa nunca funcionava dentro de DB::transaction
 * (todos os fluxos reais) e virava HTTP 500; e qualquer violação única da
 * tabela (o SQL do INSERT contém "code") era tratada como colisão de código.
 *
 * A corrida real (2 processos) não cabe numa suíte com RefreshDatabase; aqui
 * a colisão é reproduzida de forma determinística: um listener registrado
 * DEPOIS do trait troca o código calculado por um já gravado — exatamente o
 * efeito de outro processo ter gravado aquele número entre a leitura e o INSERT.
 */

/**
 * Faz as $times primeiras tentativas de INSERT do model usarem $takenCode
 * (código "velho", já gravado por outro processo). Devolve o contador de tentativas.
 *
 * @param class-string<Model> $modelClass
 * @param list<string>        $mirrors    atributos que o próprio model espelha do code (ex.: guide_number)
 */
function eccStaleCode(string $modelClass, string $takenCode, int $times = 1, array $mirrors = []): stdClass
{
    new $modelClass(); // garante o boot do model: este listener roda DEPOIS do trait

    $state = (object) ['attempts' => 0];

    $modelClass::creating(function (Model $model) use ($takenCode, $times, $mirrors, $state): void {
        $state->attempts++;

        if ($state->attempts > $times) {
            return;
        }

        $model->setAttribute('code', $takenCode);

        foreach ($mirrors as $attribute) {
            $model->setAttribute($attribute, $takenCode);
        }
    });

    return $state;
}

function eccAdvisoryLocksHeld(): int
{
    return (int) DB::selectOne(
        "select count(*) as total from pg_locks where locktype = 'advisory' and pid = pg_backend_pid() and granted",
    )->total;
}

/** @param array<string, mixed> $overrides */
function eccCashEntryPayload(array $overrides = []): array
{
    return array_merge([
        'entry_date'     => now()->toDateString(),
        'description'    => 'Consulta',
        'type'           => 'income',
        'status'         => 'paid',
        'payment_method' => PaymentMethod::Cash->value,
        'amount'         => 150,
    ], $overrides);
}

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    session(['selected_entity_id' => $this->entity->id]);
});

describe('colisão de código dentro de transação (PostgreSQL)', function (): void {
    it('recebimento de agendamento conclui com o próximo código quando outro processo gravou o mesmo número', function (): void {
        $cashFlow = app(CashFlowService::class);
        $first    = $cashFlow->create(eccCashEntryPayload());

        expect($first->code)->toBe('FLC-0000000001');

        $state = eccStaleCode(FinancialCashEntry::class, $first->code);

        $entry = DB::transaction(fn () => $cashFlow->createForSchedule(createParticularSchedule($this->entity), eccCashEntryPayload()));

        expect($entry->code)->toBe('FLC-0000000002')
            ->and($state->attempts)->toBe(2)
            ->and(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('guia (BillingClaim) refaz também o guide_number espelhado do código descartado', function (): void {
        $covenant = createParticularSchedule($this->entity)->covenant_id;
        $claim    = fn (): BillingClaim => BillingClaim::query()->create([
            'entity_id'       => $this->entity->id,
            'covenant_id'     => $covenant,
            'status'          => BillingClaimStatus::Draft->value,
            'attendance_date' => now()->toDateString(),
            'amount'          => 100,
        ]);

        $first = $claim();
        eccStaleCode(BillingClaim::class, $first->code, mirrors: ['guide_number']);

        $second = DB::transaction($claim);

        expect($second->code)->toBe('GUI-0000000002')
            ->and($second->guide_number)->toBe('GUI-0000000002')
            ->and($second->fresh()->guide_number)->toBe('GUI-0000000002');
    });

    it('importação de produtos não perde o arquivo inteiro por uma colisão de código no meio', function (): void {
        $existing = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Existente', 'unit' => 'un', 'active' => true]);

        eccStaleCode(EntityProduct::class, $existing->code);

        $row = fn (string $name): array => [
            'name'       => $name, 'unit' => 'un', 'sku' => null, 'barcode' => null, 'product_category_id' => null,
            'sale_price' => null, 'min_qty' => 0, 'max_qty' => null,
        ];

        $result = app(ProductImportService::class)->import([$row('Colírio A'), $row('Colírio B')], $this->entity->id);

        expect($result['created'])->toBe(2)
            ->and(EntityProduct::query()->where('entity_id', $this->entity->id)->orderBy('code')->pluck('code')->all())
            ->toBe(['PRD-0000000001', 'PRD-0000000002', 'PRD-0000000003']);
    });

    it('desiste após as tentativas limitadas sem envenenar a transação do chamador', function (): void {
        $first = app(CashFlowService::class)->create(eccCashEntryPayload());
        $state = eccStaleCode(FinancialCashEntry::class, $first->code, times: PHP_INT_MAX);

        DB::transaction(function () use ($state): void {
            expect(fn () => FinancialCashEntry::query()->create(eccCashEntryPayload(['entity_id' => $this->entity->id])))
                ->toThrow(UniqueConstraintViolationException::class);

            // Sem savepoint por tentativa, esta consulta falharia com 25P02.
            expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(1)
                ->and($state->attempts)->toBe(3);
        });
    });
});

describe('só a colisão do índice de código é tratada como colisão de código', function (): void {
    it('firstOrCreate/createOrFirst em corrida devolve a categoria já existente (antes: 25P02 => 500)', function (): void {
        $existing = ProductCategory::query()->create(['entity_id' => $this->entity->id, 'name' => 'Lentes IOL', 'active' => true]);

        $found = DB::transaction(fn () => ProductCategory::query()->createOrFirst(
            ['entity_id' => $this->entity->id, 'name' => 'Lentes IOL'],
            ['active' => true],
        ));

        expect($found->id)->toBe($existing->id)
            ->and(ProductCategory::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('código informado explicitamente não é trocado em silêncio por outro', function (): void {
        $first = app(CashFlowService::class)->create(eccCashEntryPayload());

        expect(fn () => DB::transaction(fn () => FinancialCashEntry::query()->create(
            eccCashEntryPayload(['entity_id' => $this->entity->id, 'code' => $first->code]),
        )))->toThrow(UniqueConstraintViolationException::class);

        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('lote de faturamento pula o agendamento já faturado por outro request e segue com os demais', function (): void {
        actingAsFinancialEntityUser($this->entity);

        $first  = createBillableSchedule($this->entity);
        $second = createBillableSchedule($this->entity, ['covenant_id' => $first->covenant_id]);

        // Outro request fatura o 1º agendamento DEPOIS do filtro de elegíveis e
        // ANTES do INSERT da guia deste lote (o lote TISS nasce entre os dois).
        TissBatch::created(function () use ($first): void {
            DB::table('billing_claims')->insert([
                'id'              => (string) Str::uuid(),
                'entity_id'       => $this->entity->id,
                'schedule_id'     => $first->id,
                'covenant_id'     => $first->covenant_id,
                'code'            => 'GUI-OUTRO-REQUEST',
                'status'          => BillingClaimStatus::Draft->value,
                'attendance_date' => now()->toDateString(),
                'amount'          => 150,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        });

        $batch = app(BillingService::class)->createBatch([
            'covenant_id'         => $first->covenant_id,
            'date_from'           => now()->subDay()->toDateString(),
            'date_until'          => now()->toDateString(),
            'unit_price'          => 150,
            'clinical_indication' => 'H52.1',
        ]);

        $batchClaims = BillingClaim::query()->where('batch_id', $batch->id)->get();

        expect($batchClaims)->toHaveCount(1)
            ->and($batchClaims->first()->schedule_id)->toBe($second->id)
            ->and($batchClaims->first()->tiss_guide_id)->not->toBeNull()
            ->and($batch->tissBatch->guides_count)->toBe(1)
            ->and($batch->total_claims)->toBe(2)
            ->and($batch->notes)->toContain(__('financial_billing.batch_pending_note', ['count' => 1]));
    });
});

describe('serialização da numeração', function (): void {
    it('segura um advisory lock de transação até o commit do chamador ao gerar o código', function (): void {
        DB::transaction(function (): void {
            $before = eccAdvisoryLocksHeld();

            app(CashFlowService::class)->create(eccCashEntryPayload());

            expect(eccAdvisoryLocksHeld())->toBeGreaterThan($before);
        });
    });

    it('ignora códigos fora do formato gerado ao calcular o próximo', function (): void {
        $first = app(CashFlowService::class)->create(eccCashEntryPayload());

        foreach (['FLC-99', 'FLC-ABCDEFGHIJ'] as $legacy) {
            FinancialCashEntry::query()->create(eccCashEntryPayload(['entity_id' => $this->entity->id, 'code' => $legacy]));
        }

        expect($first->code)->toBe('FLC-0000000001')
            ->and(app(CashFlowService::class)->create(eccCashEntryPayload())->code)->toBe('FLC-0000000002');
    });
});

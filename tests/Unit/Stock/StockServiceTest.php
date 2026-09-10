<?php

declare(strict_types=1);

use App\Exceptions\{InsufficientStockException, LotRequiredException};
use App\Models\{Entity, EntityProduct, StockLot};
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Unit não herda o TestCase::class + RefreshDatabase configurados em
// Pest.php pra 'Feature' — precisa bindar explicitamente aqui (mesmo padrão
// de tests/Unit/Tiss/PreValidateTissGuideServiceTest.php), já que este
// teste PERSISTE de verdade (Entity/EntityProduct via Eloquent + transação
// com lockForUpdate), não é um unit test isolado de banco.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Testes diretos de App\Services\Stock\StockService, sem HTTP/sessão —
 * cobre a regra de negócio (saldo/custo médio/bloqueio de saldo negativo)
 * isolada da camada de autorização (já coberta em
 * tests/Feature/Stock/StockMovementsTest.php).
 *
 * Concorrência real (duas transações disputando o mesmo lockForUpdate) não
 * é testável de forma determinística num processo Pest único — o teste
 * abaixo cobre a CORRETUDE sequencial (cada chamada aplica seu delta sobre
 * o saldo já commitado da anterior), que é a garantia que o lock existe pra
 * proteger; um teste de race condition real exigiria dois processos/
 * conexões concorrentes de verdade (fora do escopo de um teste unitário).
 */
beforeEach(function () {
    $this->service = app(StockService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->product = EntityProduct::create([
        'entity_id' => $this->entity->id,
        'name'      => 'Lente OPM Teste',
        'unit'      => 'un',
        'is_opm'    => true,
        'min_qty'   => 2,
        'active'    => true,
    ]);
});

it('primeira entrada define o custo médio igual ao custo unitário informado', function () {
    $movement = $this->service->manualIn($this->product, 10, 8.50);

    expect((float) $movement->balance_after)->toBe(10.0)
        ->and((float) $this->product->fresh()->cost_avg)->toBe(8.5);
});

it('entradas sucessivas recalculam o custo médio ponderado pela quantidade', function () {
    $this->service->manualIn($this->product, 10, 4.00); // 10 * 4 = 40
    $this->service->manualIn($this->product, 30, 8.00); // 30 * 8 = 240 -> total 280/40 = 7

    expect((float) $this->product->fresh()->cost_avg)->toBe(7.0)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(40.0);
});

it('saída sem custo informado NÃO altera o custo médio, só o saldo', function () {
    $this->service->manualIn($this->product, 10, 5.00);
    $this->service->manualOut($this->product, 4);

    $product = $this->product->fresh();
    expect((float) $product->qty_on_hand)->toBe(6.0)
        ->and((float) $product->cost_avg)->toBe(5.0);
});

it('bloqueia saída que deixaria o saldo negativo e não grava movimento nenhum', function () {
    $this->service->manualIn($this->product, 5, 10.00);

    expect(fn () => $this->service->manualOut($this->product, 6))
        ->toThrow(InsufficientStockException::class);

    expect((float) $this->product->fresh()->qty_on_hand)->toBe(5.0)
        ->and($this->product->fresh()->movements()->count())->toBe(1); // só a entrada, saída não commitou
});

it('rejeita quantity <= 0 antes de tocar no banco', function () {
    expect(fn () => $this->service->manualIn($this->product, 0, 1.00))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->service->manualOut($this->product, -5))
        ->toThrow(InvalidArgumentException::class);
});

it('ajuste por contagem física lança AdjustmentIn quando contagem > sistema', function () {
    $this->service->manualIn($this->product, 10, 5.00);

    $movement = $this->service->adjustToCountedQuantity($this->product, 15, 'Contagem mensal');

    expect($movement->type->value)->toBe('adjustment_in')
        ->and((float) $movement->quantity)->toBe(5.0)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(15.0);
});

it('ajuste por contagem física lança AdjustmentOut quando contagem < sistema', function () {
    $this->service->manualIn($this->product, 10, 5.00);

    $movement = $this->service->adjustToCountedQuantity($this->product, 3, 'Quebra encontrada na contagem');

    expect($movement->type->value)->toBe('adjustment_out')
        ->and((float) $movement->quantity)->toBe(7.0)
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(3.0);
});

it('ajuste com contagem igual ao saldo do sistema não lança movimento (retorna null)', function () {
    $this->service->manualIn($this->product, 10, 5.00);

    $result = $this->service->adjustToCountedQuantity($this->product, 10);

    expect($result)->toBeNull()
        ->and($this->product->fresh()->movements()->count())->toBe(1);
});

it('cada movimento grava balance_after como snapshot correto do saldo naquele instante', function () {
    $m1 = $this->service->manualIn($this->product, 10, 5.00);
    $m2 = $this->service->manualOut($this->product, 3);
    $m3 = $this->service->manualIn($this->product, 2, 6.00);

    expect((float) $m1->balance_after)->toBe(10.0)
        ->and((float) $m2->balance_after)->toBe(7.0)
        ->and((float) $m3->balance_after)->toBe(9.0);
});

// ── Fase 2: lote/validade ────────────────────────────────────────────────

/**
 * `$this->product` (beforeEach global acima) tem is_opm=true mas
 * requires_lot=false — usado nos testes gerais acima sem lote. Os testes
 * abaixo usam um segundo produto, explicitamente requires_lot=true, pra
 * cobrir a regra nova sem misturar com os cenários da Fase 1.
 */
beforeEach(function () {
    $this->lotProduct = EntityProduct::create([
        'entity_id'    => $this->entity->id,
        'name'         => 'OPM Lote-Rastreado Teste',
        'unit'         => 'un',
        'is_opm'       => true,
        'requires_lot' => true,
        'active'       => true,
    ]);
});

it('[REGRA DE NEGÓCIO] produto requires_lot=true sem lote informado lança LotRequiredException', function () {
    expect(fn () => $this->service->manualIn($this->lotProduct, 5, 10.00))
        ->toThrow(LotRequiredException::class);

    expect((float) $this->lotProduct->fresh()->qty_on_hand)->toBe(0.0);
});

it('findOrCreateLot cria o lote na primeira chamada e reaproveita nas seguintes (idempotente)', function () {
    $lot1 = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-001', now()->addYear());
    $lot2 = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-001', now()->addYear());

    expect($lot1->id)->toBe($lot2->id)
        ->and(StockLot::query()->where('entity_product_id', $this->lotProduct->id)->count())->toBe(1);
});

it('entrada com lote soma saldo/custo do LOTE e do PRODUTO em paralelo', function () {
    $lot = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-A', now()->addMonths(6));

    $this->service->manualIn($this->lotProduct, 10, 100.00, lot: $lot);
    $this->service->manualIn($this->lotProduct, 10, 200.00, lot: $lot);

    $freshLot = $lot->fresh();
    expect((float) $freshLot->qty_on_hand)->toBe(20.0)
        ->and((float) $freshLot->cost_avg)->toBe(150.0) // (10*100+10*200)/20
        ->and((float) $this->lotProduct->fresh()->qty_on_hand)->toBe(20.0)
        ->and((float) $this->lotProduct->fresh()->cost_avg)->toBe(150.0);
});

it('[REGRA DE NEGÓCIO] saída não pode exceder o saldo do LOTE mesmo com produto tendo saldo em OUTRO lote', function () {
    $lotA = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-A', now()->addYear());
    $lotB = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-B', now()->addYear());

    $this->service->manualIn($this->lotProduct, 3, 50.00, lot: $lotA);
    $this->service->manualIn($this->lotProduct, 20, 50.00, lot: $lotB);

    // produto tem 23 no agregado, mas lote A só tem 3 — pedir 10 do lote A tem que falhar.
    expect(fn () => $this->service->manualOut($this->lotProduct, 10, lot: $lotA))
        ->toThrow(InsufficientStockException::class);

    expect((float) $lotA->fresh()->qty_on_hand)->toBe(3.0)
        ->and((float) $this->lotProduct->fresh()->qty_on_hand)->toBe(23.0); // nada mudou, transação não commitou
});

it('saída de um lote específico reduz o saldo DAQUELE lote e o agregado do produto, sem tocar no outro lote', function () {
    $lotA = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-A', now()->addYear());
    $lotB = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-B', now()->addYear());

    $this->service->manualIn($this->lotProduct, 10, 50.00, lot: $lotA);
    $this->service->manualIn($this->lotProduct, 10, 50.00, lot: $lotB);

    $this->service->manualOut($this->lotProduct, 4, lot: $lotA);

    expect((float) $lotA->fresh()->qty_on_hand)->toBe(6.0)
        ->and((float) $lotB->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $this->lotProduct->fresh()->qty_on_hand)->toBe(16.0);
});

it('lote informado que pertence a OUTRO produto é rejeitado', function () {
    $otherProduct = EntityProduct::create([
        'entity_id' => $this->entity->id, 'name' => 'Outro produto', 'unit' => 'un', 'active' => true,
    ]);
    $foreignLot = $this->service->findOrCreateLot($otherProduct, 'LOTE-ALHEIO', now()->addYear());

    expect(fn () => $this->service->manualIn($this->lotProduct, 5, 10.00, lot: $foreignLot))
        ->toThrow(InvalidArgumentException::class);
});

it('ajuste por contagem física DE UM LOTE calcula o delta contra o saldo do lote, não do produto', function () {
    $lotA = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-A', now()->addYear());
    $lotB = $this->service->findOrCreateLot($this->lotProduct, 'LOTE-B', now()->addYear());
    $this->service->manualIn($this->lotProduct, 10, 50.00, lot: $lotA);
    $this->service->manualIn($this->lotProduct, 10, 50.00, lot: $lotB);

    // contei 7 no lote A (perdeu 3), lote B nem foi mexido.
    $movement = $this->service->adjustToCountedQuantity($this->lotProduct, 7, 'Contagem', lot: $lotA);

    expect($movement->type->value)->toBe('adjustment_out')
        ->and((float) $lotA->fresh()->qty_on_hand)->toBe(7.0)
        ->and((float) $lotB->fresh()->qty_on_hand)->toBe(10.0)
        ->and((float) $this->lotProduct->fresh()->qty_on_hand)->toBe(17.0);
});

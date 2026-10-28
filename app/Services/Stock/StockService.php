<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\StockMovementType;
use App\Exceptions\{ExpiredLotException, InsufficientStockException, LotRequiredException, StockReversalException};
use App\Models\{EntityProduct, StockLot, StockMovement};
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Único ponto de escrita de saldo/custo de estoque (produto E lote).
 * Nenhum código fora deste service deve alterar `qty_on_hand`/`cost_avg` de
 * EntityProduct/StockLot ou inserir em `stock_movements` diretamente — ver
 * docs nos models e nas migrations.
 *
 * Concorrência: cada chamada abre transação própria e usa `lockForUpdate()`
 * na linha do produto E (quando informado) na linha do lote — duas baixas
 * simultâneas do mesmo item/lote (ex.: 2 cirurgias com o mesmo OPM abertas
 * ao mesmo tempo) serializam em vez de correr. Sem isso, ambas leriam o
 * mesmo saldo inicial e a segunda sobrescreveria o resultado da primeira
 * (lost update), podendo autorizar consumo além do saldo real.
 *
 * Lote (Fase 2): quando `entity_products.requires_lot=true`, TODO movimento
 * exige um StockLot (`$lot` não pode ser null) — validado aqui, não só na
 * UI (LotRequiredException). O saldo do produto continua sendo o agregado;
 * o saldo do lote é uma segunda contabilidade em paralelo, atualizada pela
 * MESMA operação — por construção, para um produto lot-tracked,
 * `entity_products.qty_on_hand` sempre equivale a SUM(stock_lots.qty_on_hand)
 * dos seus lotes, porque nenhum movimento desse produto passa sem lote.
 *
 * Tenant-agnóstico DE PROPÓSITO: não reconsulta session('selected_entity_id')
 * nem valida posse — quem chama (controller/job/service de domínio) já
 * resolveu e validou o EntityProduct/StockLot corretos para o tenant certo.
 * Isso permite reuso por jobs/CLI (que resolvem a entidade de outra forma —
 * ver CLAUDE.md, "Multi-tenancy por sessão") sem depender de HTTP session.
 */
class StockService
{
    /**
     * Entrada por compra/recebimento de fornecedor (Fase 4 chama isto).
     */
    public function purchaseIn(
        EntityProduct $product,
        float $quantity,
        float $unitCost,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?StockLot $lot = null,
    ): StockMovement {
        return $this->registerMovement($product, StockMovementType::PurchaseIn, $quantity, $unitCost, $note, $occurredAt, $referenceType, $referenceId, $lot);
    }

    /**
     * Entrada manual (doação, correção, transferência recebida) — lançada
     * pela tela Panel/Stock/Movements.
     */
    public function manualIn(
        EntityProduct $product,
        float $quantity,
        ?float $unitCost = null,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
        ?StockLot $lot = null,
    ): StockMovement {
        return $this->registerMovement($product, StockMovementType::ManualIn, $quantity, $unitCost, $note, $occurredAt, null, null, $lot);
    }

    /**
     * Saída por consumo em procedimento/cirurgia (Fase 3 chama isto).
     */
    public function consumptionOut(
        EntityProduct $product,
        float $quantity,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?StockLot $lot = null,
    ): StockMovement {
        return $this->registerMovement($product, StockMovementType::ConsumptionOut, $quantity, null, $note, $occurredAt, $referenceType, $referenceId, $lot);
    }

    /**
     * Estorna um CONSUMO (implante não usado, lançamento errado): nova linha de
     * sentido oposto no ledger (`consumption_reversal`), no MESMO produto e
     * lote, sob a trava produto → lote. O consumo original nunca é editado.
     *
     * Idempotente: um consumo só é estornado uma vez (índice único em
     * `reversal_of_id`); chamar de novo devolve o estorno que já existe, sem
     * mexer no saldo. Recusa (StockReversalException) o que não é consumo.
     *
     * Custo: o produto volta pelo custo médio que o consumo registrou; o lote
     * mantém o PRÓPRIO custo médio, intocado (o consumo não grava o custo do lote
     * e o do produto é outra média — ver registerMovement()).
     *
     * Tenant-agnóstico como o resto do service: quem chama já resolveu o
     * movimento. `$entityId` (opcional) é a clínica de quem pede: se o
     * movimento for de outra, a operação é recusada.
     */
    public function reverseConsumption(
        StockMovement $consumption,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $entityId = null,
    ): StockMovement {
        return DB::transaction(function () use ($consumption, $note, $occurredAt, $referenceType, $referenceId, $entityId) {
            // Mesma ordem de registerMovement(): produto primeiro, depois o lote.
            $product = EntityProduct::query()->whereKey($consumption->entity_product_id)->lockForUpdate()->first();

            if ($product === null) {
                throw new StockReversalException('Produto do consumo não encontrado.');
            }

            if ($consumption->stock_lot_id !== null) {
                StockLot::query()->whereKey($consumption->stock_lot_id)->lockForUpdate()->first();
            }

            // Relido sob a trava do produto: dois estornos simultâneos se serializam aqui.
            $current = StockMovement::query()
                ->withoutGlobalScopes()
                ->whereKey($consumption->getKey())
                ->first();

            if ($current === null
                || $current->type !== StockMovementType::ConsumptionOut
                || (string) $current->entity_id !== (string) $product->entity_id
                || ($entityId !== null && (string) $current->entity_id !== $entityId)) {
                throw new StockReversalException('Movimento não é um consumo desta clínica.');
            }

            $existing = StockMovement::query()->withoutGlobalScopes()->where('reversal_of_id', $current->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $lot = $current->stock_lot_id !== null ? StockLot::query()->withoutGlobalScopes()->find($current->stock_lot_id) : null;

            return $this->registerMovement(
                $product,
                StockMovementType::ConsumptionReversal,
                (float) $current->quantity,
                $current->unit_cost !== null ? (float) $current->unit_cost : null,
                $note,
                $occurredAt,
                $referenceType,
                $referenceId,
                $lot,
                reversalOfId: (string) $current->getKey(),
            );
        });
    }

    /** Saída manual avulsa (uso não ligado a um procedimento específico). */
    public function manualOut(EntityProduct $product, float $quantity, ?string $note = null, ?CarbonInterface $occurredAt = null, ?StockLot $lot = null): StockMovement
    {
        return $this->registerMovement($product, StockMovementType::ManualOut, $quantity, null, $note, $occurredAt, null, null, $lot);
    }

    /** Perda/quebra/vencimento. */
    public function loss(EntityProduct $product, float $quantity, ?string $note = null, ?CarbonInterface $occurredAt = null, ?StockLot $lot = null): StockMovement
    {
        return $this->registerMovement($product, StockMovementType::Loss, $quantity, null, $note, $occurredAt, null, null, $lot);
    }

    /** Devolução ao fornecedor. */
    public function returnOut(EntityProduct $product, float $quantity, ?string $note = null, ?CarbonInterface $occurredAt = null, ?StockLot $lot = null): StockMovement
    {
        return $this->registerMovement($product, StockMovementType::ReturnOut, $quantity, null, $note, $occurredAt, null, null, $lot);
    }

    /**
     * Ponto de entrada ÚNICO da tela de lançamento livre
     * (Panel/Stock/Movements) — aceita qualquer tipo de
     * StockMovementType::manualTypes() (inclui os dois ajustes de balanço,
     * que os wrappers dedicados acima não cobrem porque cada um tem tipo
     * fixo). Rejeita explicitamente purchase_in/consumption_out: esses só
     * nascem de fluxo de negócio (compra/procedimento), nunca de
     * lançamento livre — StockMovementRequest já valida isso, mas o service
     * não confia soltar essa regra só na camada HTTP.
     */
    public function manual(
        EntityProduct $product,
        StockMovementType $type,
        float $quantity,
        ?float $unitCost = null,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
        ?StockLot $lot = null,
    ): StockMovement {
        if (! in_array($type, StockMovementType::manualTypes(), true)) {
            throw new InvalidArgumentException("Tipo [{$type->value}] não é lançável manualmente.");
        }

        return $this->registerMovement($product, $type, $quantity, $unitCost, $note, $occurredAt, null, null, $lot);
    }

    /**
     * Busca um lote existente pelo número (ex.: informado num
     * lançamento de entrada) ou cria um novo — idempotente por
     * (entity_product_id, lot_number), mesmo padrão de
     * IolLensCatalogService::findOrCreateModel().
     *
     * Corrida: dois lançamentos simultâneos da MESMA entrada nova (2 pessoas
     * digitando o mesmo número de lote ao mesmo tempo) podem colidir no
     * SELECT-then-INSERT — a constraint UNIQUE(entity_product_id, lot_number)
     * do banco garante que só um INSERT vence; o outro cai no catch e
     * re-busca o registro que acabou de ser criado pelo concorrente.
     */
    public function findOrCreateLot(EntityProduct $product, string $lotNumber, ?CarbonInterface $expiryDate = null): StockLot
    {
        $lotNumber = trim($lotNumber);

        $existing = StockLot::query()
            ->where('entity_product_id', $product->id)
            ->where('lot_number', $lotNumber)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return StockLot::create([
                'entity_id'         => $product->entity_id,
                'entity_product_id' => $product->id,
                'lot_number'        => $lotNumber,
                'expiry_date'       => $expiryDate,
                'active'            => true,
            ]);
        } catch (QueryException $e) {
            // 23505 = unique_violation (Postgres) — concorrente venceu a corrida.
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            return StockLot::query()
                ->where('entity_product_id', $product->id)
                ->where('lot_number', $lotNumber)
                ->firstOrFail();
        }
    }

    /**
     * Ajuste de balanço a partir de uma CONTAGEM FÍSICA: recebe o saldo
     * contado (absoluto, não o delta) e lança automaticamente
     * AdjustmentIn/AdjustmentOut com a diferença em relação ao saldo atual
     * do sistema. Sob lock — a leitura do saldo atual e o cálculo do delta
     * acontecem dentro da MESMA transação que grava o ajuste, senão uma
     * movimentação concorrente entre a leitura e a gravação invalidaria o
     * delta calculado.
     *
     * `$lot` presente = contagem é DE UM LOTE específico (você contou as
     * unidades físicas daquele lote na prateleira) — o delta é calculado
     * contra o saldo do LOTE, não do produto agregado (contagem de produto
     * lot-tracked sem dizer qual lote não faz sentido: fisicamente você
     * sempre está contando um lote de cada vez).
     */
    public function adjustToCountedQuantity(EntityProduct $product, float $countedQuantity, ?string $note = null, ?StockLot $lot = null): ?StockMovement
    {
        if ($countedQuantity < 0) {
            throw new InvalidArgumentException('countedQuantity não pode ser negativa.');
        }

        return DB::transaction(function () use ($product, $countedQuantity, $note, $lot) {
            $currentQty = $lot !== null
                ? (float) StockLot::query()->whereKey($lot->id)->lockForUpdate()->value('qty_on_hand')
                : (float) EntityProduct::query()->whereKey($product->id)->lockForUpdate()->value('qty_on_hand');

            $delta = round($countedQuantity - $currentQty, 3);

            if ($delta === 0.0) {
                return null; // contagem bate com o sistema — nada a lançar
            }

            $type = $delta > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;

            return $this->registerMovement($product, $type, abs($delta), null, $note, null, null, null, $lot);
        });
    }

    /**
     * Grava a movimentação + atualiza saldo/custo médio do produto (e do
     * lote, quando informado) sob lock. Chamado tanto direto pelos métodos
     * públicos quanto de dentro da transação de adjustToCountedQuantity() —
     * DB::transaction() do Laravel suporta aninhamento via savepoint.
     */
    private function registerMovement(
        EntityProduct $product,
        StockMovementType $type,
        float $quantity,
        ?float $unitCost,
        ?string $note,
        ?CarbonInterface $occurredAt,
        ?string $referenceType,
        ?string $referenceId,
        ?StockLot $lot = null,
        ?string $reversalOfId = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('quantity deve ser maior que zero.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $unitCost, $note, $occurredAt, $referenceType, $referenceId, $lot, $reversalOfId) {
            /** @var EntityProduct $lockedProduct */
            $lockedProduct = EntityProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($lockedProduct->requires_lot && $lot === null) {
                throw new LotRequiredException($lockedProduct);
            }

            $lockedLot = null;

            if ($lot !== null) {
                /** @var StockLot $lockedLot */
                $lockedLot = StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

                if ((string) $lockedLot->entity_product_id !== (string) $lockedProduct->id) {
                    throw new InvalidArgumentException('Lote informado não pertence a este produto.');
                }

                // Material vencido nunca é usado em paciente: o consumo recusa o lote
                // vencido NA DATA DO USO (`occurredAt`; o registro tardio da cirurgia de ontem vale
                // contra ontem), lido sob a trava — a edição retroativa da validade vale.
                // Perda, ajuste, devolução e o estorno não passam por aqui.
                if ($type === StockMovementType::ConsumptionOut
                    && $lockedLot->expiry_date !== null
                    && $lockedLot->expiry_date->toDateString() < ($occurredAt ?? now())->format('Y-m-d')) {
                    throw new ExpiredLotException($lockedProduct, $lockedLot);
                }
            }

            // ── Saldo/custo do LOTE (se houver) — checado ANTES do agregado:
            // é a restrição mais específica (posso ter 50 no produto mas só 3
            // neste lote — fisicamente não dá pra "puxar" de outro lote sem o
            // usuário escolher explicitamente qual). ──────────────────────
            $newLotQty  = null;
            $newLotCost = null;

            if ($lockedLot !== null) {
                $oldLotQty  = (float) $lockedLot->qty_on_hand;
                $oldLotCost = (float) $lockedLot->cost_avg;

                $newLotQty = round($oldLotQty + ($type->direction() * $quantity), 3);

                if ($newLotQty < 0) {
                    throw new InsufficientStockException($lockedProduct, $quantity, $oldLotQty, $lockedLot);
                }

                $newLotCost = $oldLotCost;

                // O estorno de consumo devolve a unidade pelo custo do PRÓPRIO lote,
                // ou seja, não mexe nele. O `unit_cost` do consumo é o custo médio do
                // PRODUTO (a saída nunca grava o custo do lote): entrar com ele na
                // média do lote distorce — 9 un. a 100 + 1 estornada a 150 = 105 num
                // lote que sempre custou 100 (e, com o lote zerado, o lote passava a
                // valer o custo do produto). Só entrada que traz custo de aquisição
                // (compra, entrada manual) pondera.
                if ($type->isInbound() && $unitCost !== null && $type !== StockMovementType::ConsumptionReversal) {
                    $totalLotQty = $oldLotQty + $quantity;
                    $newLotCost  = $totalLotQty > 0
                        ? round((($oldLotQty * $oldLotCost) + ($quantity * $unitCost)) / $totalLotQty, 4)
                        : $unitCost;
                }
            }

            // ── Saldo/custo do PRODUTO (agregado) — lógica original da Fase 1,
            // inalterada. ──────────────────────────────────────────────────
            $oldQty  = (float) $lockedProduct->qty_on_hand;
            $oldCost = (float) $lockedProduct->cost_avg;

            $newQty = round($oldQty + ($type->direction() * $quantity), 3);

            if ($newQty < 0) {
                throw new InsufficientStockException($lockedProduct, $quantity, $oldQty);
            }

            $movementUnitCost = $oldCost > 0 || $unitCost !== null ? $oldCost : null;
            $newCostAvg       = $oldCost;

            if ($type->isInbound() && $unitCost !== null) {
                // Custo médio ponderado móvel: recalcula a cada entrada. Saídas
                // NUNCA alteram cost_avg — apenas registram (informativo, pra
                // relatório de custo/margem) o custo médio vigente no momento
                // da saída, que é o `movementUnitCost` calculado acima.
                $totalQty   = $oldQty + $quantity;
                $newCostAvg = $totalQty > 0
                    ? round((($oldQty * $oldCost) + ($quantity * $unitCost)) / $totalQty, 4)
                    : $unitCost;
                $movementUnitCost = $unitCost;
            }

            $lockedProduct->qty_on_hand = $newQty;
            $lockedProduct->cost_avg    = $newCostAvg;
            $lockedProduct->save();

            if ($lockedLot !== null) {
                $lockedLot->qty_on_hand = $newLotQty;
                $lockedLot->cost_avg    = $newLotCost;
                $lockedLot->save();
            }

            return StockMovement::create([
                'entity_id'         => $lockedProduct->entity_id,
                'entity_product_id' => $lockedProduct->id,
                'stock_lot_id'      => $lockedLot?->id,
                'reversal_of_id'    => $reversalOfId,
                'type'              => $type,
                'quantity'          => $quantity,
                // Custo do PRODUTO fica no campo principal (mantém contrato da
                // Fase 1); o custo específico do lote, quando divergente, não
                // tem coluna própria no movimento — o extrato por lote consulta
                // stock_lots.cost_avg diretamente. Por isso o estorno não usa
                // este valor para o lote (ver acima).
                'unit_cost'      => $movementUnitCost,
                'balance_after'  => $newQty,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'note'           => $note,
                'occurred_at'    => $occurredAt ?? now(),
            ]);
        });
    }
}

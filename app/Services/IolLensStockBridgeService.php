<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{EntityIolLens, EntityProduct, ProductCategory};
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de escrita do par EntityProduct + EntityIolLens — toda lente
 * IOL da clínica É um produto de estoque real (1:1 obrigatório, ver
 * docblock de App\Models\EntityIolLens). Reunido aqui em vez de espalhado
 * em App\Http\Controllers\Stock\IolLensesController pra ser reaproveitado
 * também pelo comando de backfill (App\Console\Commands\
 * MigrateIolLensesToStock) sem duplicar a lógica de categoria/produto.
 *
 * NÃO mexe em qty_on_hand/cost_avg — isso continua sendo escrito
 * exclusivamente por App\Services\Stock\StockService::registerMovement()
 * quando (e se) a clínica de fato registrar entradas/saídas físicas da
 * lente. Uma lente recém-criada nasce com saldo 0, sempre.
 */
class IolLensStockBridgeService
{
    public const DEFAULT_CATEGORY_NAME = 'Lentes IOL';

    /**
     * Categoria de estoque padrão pra lentes IOL, uma por entidade —
     * mesmo idioma de findOrCreate já usado em
     * IolLensCatalogService::findOrCreateModel() e
     * StockService::findOrCreateLot() (dedupe idempotente, sem duplicar em
     * corrida graças à unique(entity_id, name) de product_categories).
     */
    public function findOrCreateCategory(string $entityId): ProductCategory
    {
        return ProductCategory::query()->firstOrCreate(
            ['entity_id' => $entityId, 'name' => self::DEFAULT_CATEGORY_NAME],
            ['active' => true],
        );
    }

    /**
     * @param array{
     *     manufacturer?: ?string, model_name: string, category?: ?string,
     *     diopter_min?: ?float, diopter_max?: ?float, price?: ?float,
     *     image_path?: ?string, active?: bool, iol_lens_model_id?: ?string,
     * } $data
     */
    public function create(string $entityId, array $data): EntityIolLens
    {
        return DB::transaction(function () use ($entityId, $data) {
            $product = EntityProduct::create([
                'entity_id'           => $entityId,
                'product_category_id' => $this->findOrCreateCategory($entityId)->id,
                'name'                => $data['model_name'],
                'manufacturer'        => $data['manufacturer'] ?? null,
                'unit'                => 'un',
                // TODO(fase-3-tiss): is_opm=true é seguro hoje (só vira
                // badge/checkbox visual, não está cablado em faturamento
                // TISS — ver TissGuideItem.php) — revisar quando o
                // faturamento OPM/TISS for implementado de verdade.
                'is_opm'     => true,
                'sale_price' => $data['price'] ?? null,
                'image_path' => $data['image_path'] ?? null,
                'active'     => $data['active'] ?? true,
            ]);

            return EntityIolLens::create([
                'entity_id'         => $entityId,
                'entity_product_id' => $product->id,
                'iol_lens_model_id' => $data['iol_lens_model_id'] ?? null,
                'category'          => $data['category'] ?? null,
                'diopter_min'       => $data['diopter_min'] ?? null,
                'diopter_max'       => $data['diopter_max'] ?? null,
            ]);
        });
    }

    /**
     * `image_path` só é tocado no produto se a chave vier presente em
     * $data (`array_key_exists`, não `??`) — em edição sem novo upload, o
     * controller simplesmente não inclui a chave, preservando a foto atual.
     */
    public function update(EntityIolLens $lens, array $data): EntityIolLens
    {
        return DB::transaction(function () use ($lens, $data) {
            $productUpdate = [
                'name'         => $data['model_name'],
                'manufacturer' => $data['manufacturer'] ?? null,
                'sale_price'   => $data['price'] ?? null,
                'active'       => $data['active'] ?? $lens->entityProduct->active,
            ];

            if (array_key_exists('image_path', $data)) {
                $productUpdate['image_path'] = $data['image_path'];
            }

            $lens->entityProduct->update($productUpdate);

            $lens->update([
                'iol_lens_model_id' => $data['iol_lens_model_id'] ?? $lens->iol_lens_model_id,
                'category'          => $data['category'] ?? null,
                'diopter_min'       => $data['diopter_min'] ?? null,
                'diopter_max'       => $data['diopter_max'] ?? null,
            ]);

            return $lens->fresh(['entityProduct']);
        });
    }

    /**
     * Soft-deleta a lente E o produto de estoque vinculado — mesmo padrão
     * simples de App\Http\Controllers\Stock\ProductsController::destroy()
     * (sem guarda extra de histórico de movimentação; soft delete preserva
     * stock_movements/stock_lots por FK, nunca hard-delete).
     */
    public function delete(EntityIolLens $lens): void
    {
        DB::transaction(function () use ($lens) {
            $product = $lens->entityProduct;
            $lens->delete();
            $product?->delete();
        });
    }
}

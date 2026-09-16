<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialização de uma lente IOL (catarata) DA CLÍNICA —
 * App\Models\EntityIolLens. Fabricante/nome/preço/foto/status vêm do
 * EntityProduct vinculado (1:1 obrigatório — ver docblock do model), mas o
 * FORMATO DE SAÍDA aqui é o MESMO de antes da migração pro estoque
 * (manufacturer/model_name/price/image_url/active como chaves top-level) —
 * de propósito, pra `Index.vue`/`IolLensFormModal.vue` não precisarem
 * mudar. `entityProduct` precisa vir eager-loaded (ver
 * IolLensesController::index()/show()); sem isso `$this->entityProduct` é
 * null e as chaves saem vazias (não deve acontecer em uso normal, já que o
 * vínculo é obrigatório no schema).
 *
 * `diopter_range`/`price_formatted` são campos DERIVADOS só pra exibição —
 * o form de edição client-side continua usando diopter_min/diopter_max/
 * price crus (numéricos), nunca os formatados.
 */
class EntityIolLensResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->entityProduct;

        $diopterMin = $this->diopter_min !== null ? (float) $this->diopter_min : null;
        $diopterMax = $this->diopter_max !== null ? (float) $this->diopter_max : null;
        $price      = $product?->sale_price !== null ? (float) $product->sale_price : null;

        return [
            'id'                => $this->id,
            'iol_lens_model_id' => $this->iol_lens_model_id,
            'manufacturer'      => $product?->manufacturer,
            'model_name'        => $product?->name,
            'category'          => $this->category,
            'diopter_min'       => $diopterMin,
            'diopter_max'       => $diopterMax,
            'diopter_range'     => $this->formatDiopterRange($diopterMin, $diopterMax),
            'price'             => $price,
            'price_formatted'   => $price !== null ? $this->formatCurrency($price) : null,
            'image_url'         => $product?->image_url,
            'active'            => (bool) ($product?->active ?? false),
            'created_at'        => $this->created_at?->format('d/m/Y H:i'),
            'entity_product_id' => $this->entity_product_id,
            'stock'             => $product === null ? null : [
                'id'          => $product->id,
                'name'        => $product->name,
                'code'        => $product->code,
                'unit_label'  => $product->unit?->label(),
                'qty_on_hand' => (float) $product->qty_on_hand,
            ],
        ];
    }

    /**
     * "+10.0 a +30.0 D" — sinal sempre explícito (dioptria positiva/negativa
     * é informação clínica relevante), null quando falta min OU max.
     */
    private function formatDiopterRange(?float $min, ?float $max): ?string
    {
        if ($min === null || $max === null) {
            return null;
        }

        return sprintf('%+.1f a %+.1f D', $min, $max);
    }

    /**
     * "R$ X.XXX,XX" — mesmo padrão (number_format manual, vírgula/ponto BR)
     * já usado em PlansController/CommissionsController/etc. neste projeto;
     * evita depender de Number::currency() (requer ext-intl) só pra manter
     * consistência com o resto da base.
     */
    private function formatCurrency(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialização de um item de inventário de lente IOL (catarata) DA CLÍNICA
 * — App\Models\EntityIolLens.
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
        $diopterMin = $this->diopter_min !== null ? (float) $this->diopter_min : null;
        $diopterMax = $this->diopter_max !== null ? (float) $this->diopter_max : null;
        $price      = $this->price !== null ? (float) $this->price : null;

        return [
            'id'                => $this->id,
            'iol_lens_model_id' => $this->iol_lens_model_id,
            'manufacturer'      => $this->manufacturer,
            'model_name'        => $this->model_name,
            'category'          => $this->category,
            'diopter_min'       => $diopterMin,
            'diopter_max'       => $diopterMax,
            'diopter_range'     => $this->formatDiopterRange($diopterMin, $diopterMax),
            'price'             => $price,
            'price_formatted'   => $price !== null ? $this->formatCurrency($price) : null,
            'image_url'         => $this->image_url,
            'active'            => (bool) $this->active,
            'created_at'        => $this->created_at?->format('d/m/Y H:i'),
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

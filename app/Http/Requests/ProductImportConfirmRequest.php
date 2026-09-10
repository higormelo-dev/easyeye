<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StockUnit;
use App\Services\Stock\ProductImportService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirmação da importação em massa de produtos (GAP fechado — revisão
 * pós-Fase 4). Valida a FORMA do payload que a tela ecoa de volta do
 * preview (ver App\Services\Stock\ProductImportService::preview()) — a
 * revalidação de NEGÓCIO (sku/barcode/categoria ainda válidos) acontece de
 * novo dentro de ProductImportService::import(), propositalmente, porque
 * tempo pode ter passado entre o preview e o clique em confirmar.
 */
class ProductImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rows'                       => ['required', 'array', 'min:1', 'max:' . ProductImportService::MAX_ROWS],
            'rows.*.name'                => ['required', 'string', 'max:255'],
            'rows.*.unit'                => ['required', Rule::enum(StockUnit::class)],
            'rows.*.sku'                 => ['nullable', 'string', 'max:100'],
            'rows.*.barcode'             => ['nullable', 'string', 'max:64'],
            'rows.*.product_category_id' => ['nullable', 'uuid'],
            'rows.*.sale_price'          => ['nullable', 'numeric', 'min:0'],
            'rows.*.min_qty'             => ['nullable', 'numeric', 'min:0'],
            'rows.*.max_qty'             => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->input('rows', []);
    }
}

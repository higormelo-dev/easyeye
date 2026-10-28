<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\{ImplantKind, ImplantStockSource};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registro do implante na cirurgia (estoque da clínica ou unidade de fora). */
class OperationImplantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $stock = $this->input('source', ImplantStockSource::ClinicStock->value) !== ImplantStockSource::External->value;

        return [
            'source'                => ['nullable', Rule::enum(ImplantStockSource::class)],
            'eye'                   => ['required', Rule::in(['od', 'oe'])],
            'entity_product_id'     => [$stock ? 'required' : 'nullable', 'uuid'],
            'stock_lot_id'          => ['nullable', 'uuid'],
            'serial_number'         => ['nullable', 'string', 'max:60'],
            'power'                 => ['nullable', 'numeric', 'between:-10,60'],
            'quantity'              => ['nullable', 'integer', 'between:1,99'],
            'anvisa_registration'   => ['nullable', 'string', 'max:30'],
            'model_reference'       => ['nullable', 'string', 'max:80'],
            'implanted_at'          => ['nullable', 'date'],
            'plan_deviation_reason' => ['nullable', 'string', 'max:2000'],
            // Unidade de fora do estoque (consignado).
            'kind'         => ['nullable', Rule::enum(ImplantKind::class)],
            'product_name' => [$stock ? 'nullable' : 'required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'lot_number'   => ['nullable', 'string', 'max:60'],
            'expiry_date'  => ['nullable', 'date'],
        ];
    }
}

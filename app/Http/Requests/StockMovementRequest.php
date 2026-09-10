<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\{EntityProduct, StockLot};
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Validação do lançamento MANUAL de movimentação de estoque (Panel/Stock/
 * Movements). `type` é restrito a StockMovementType::manualTypes() —
 * purchase_in (nasce de compra, Fase 4) e consumption_out (nasce do
 * prontuário, Fase 3) NUNCA chegam por este formulário livre.
 *
 * Lote (Fase 2): produto `requires_lot=true` exige EXATAMENTE um dos dois —
 * `stock_lot_id` (lote já existente, único caminho pra SAÍDA) OU
 * `new_lot_number` (+ opcional `new_lot_expiry_date`, só faz sentido numa
 * ENTRADA — não dá pra "criar" um lote novo tirando estoque dele). A regra
 * é validada aqui E de novo em StockService::registerMovement()
 * (LotRequiredException) — nunca confiar só na camada HTTP.
 */
class StockMovementRequest extends FormRequest
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
        $entityId = session('selected_entity_id');

        return [
            'entity_product_id' => [
                'required',
                'uuid',
                Rule::exists('entity_products', 'id')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at'),
            ],
            'type' => [
                'required',
                Rule::in(array_map(fn (StockMovementType $t) => $t->value, StockMovementType::manualTypes())),
            ],
            'quantity'     => ['required', 'numeric', 'gt:0'],
            'unit_cost'    => ['nullable', 'numeric', 'min:0'],
            'stock_lot_id' => [
                'nullable',
                'uuid',
                Rule::exists('stock_lots', 'id')
                    ->where('entity_id', $entityId)
                    ->where('entity_product_id', $this->input('entity_product_id'))
                    ->whereNull('deleted_at'),
            ],
            'new_lot_number'      => ['nullable', 'string', 'max:100'],
            'new_lot_expiry_date' => ['nullable', 'date'],
            'note'                => ['nullable', 'string', 'max:1000'],
            'occurred_at'         => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_product_id.required' => trans('validation.custom.generic.required'),
            'quantity.required'          => trans('validation.custom.generic.required'),
            'quantity.gt'                => __('stock.quantity_must_be_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Campos base ainda inválidos (ex.: entity_product_id não existe) —
            // não empilha erro de lote em cima de um erro que já invalida tudo.
            if ($validator->errors()->has('entity_product_id') || $validator->errors()->has('type')) {
                return;
            }

            $product = EntityProduct::query()
                ->where('entity_id', session('selected_entity_id'))
                ->find($this->input('entity_product_id'));

            if ($product === null) {
                return;
            }

            $hasExistingLot = filled($this->input('stock_lot_id'));
            $hasNewLot      = filled($this->input('new_lot_number'));

            if ($hasExistingLot && $hasNewLot) {
                $validator->errors()->add('stock_lot_id', __('stock.lot_pick_one'));

                return;
            }

            if ($product->requires_lot && ! $hasExistingLot && ! $hasNewLot) {
                $validator->errors()->add('stock_lot_id', __('stock.lot_required', ['product' => $product->name]));

                return;
            }

            $type = StockMovementType::tryFrom((string) $this->input('type'));

            if ($hasNewLot && $type !== null && ! $type->isInbound()) {
                $validator->errors()->add('new_lot_number', __('stock.new_lot_only_on_inbound'));
            }
        });
    }

    public function product(): EntityProduct
    {
        return EntityProduct::query()
            ->where('entity_id', session('selected_entity_id'))
            ->findOrFail($this->input('entity_product_id'));
    }

    public function movementType(): StockMovementType
    {
        return StockMovementType::from($this->input('type'));
    }

    /** Lote EXISTENTE selecionado — null se o usuário optou por criar um novo (ou produto não é lot-tracked). */
    public function existingLot(): ?StockLot
    {
        $id = $this->input('stock_lot_id');

        if (blank($id)) {
            return null;
        }

        return StockLot::query()
            ->where('entity_id', session('selected_entity_id'))
            ->find($id);
    }

    public function newLotNumber(): ?string
    {
        return $this->filled('new_lot_number') ? trim((string) $this->input('new_lot_number')) : null;
    }

    public function newLotExpiryDate(): ?Carbon
    {
        return $this->filled('new_lot_expiry_date') ? Carbon::parse($this->input('new_lot_expiry_date')) : null;
    }
}

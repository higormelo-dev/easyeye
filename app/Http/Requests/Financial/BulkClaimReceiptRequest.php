<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Models\BillingClaim;
use App\Services\Financial\BillingBulkReceiptService;
use Illuminate\Validation\Validator;

/**
 * "Registrar recebimento" das guias selecionadas na aba Guias: data/forma/
 * observação (BatchReceiptRequest) + o valor de cada guia (> 0 e até o valor
 * da guia), até BillingBulkReceiptService::MAX_CLAIMS guias. Os ids são
 * conferidos na clínica da sessão numa consulta só (whereIn), que também dá o
 * valor da guia para o teto; o service confere tudo de novo sob lock.
 */
class BulkClaimReceiptRequest extends BatchReceiptRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'items'               => ['required', 'array', 'min:1', 'max:' . BillingBulkReceiptService::MAX_CLAIMS],
            'items.*'             => ['required', 'array:claim_id,paid_amount'],
            'items.*.claim_id'    => ['required', 'string', 'uuid', 'distinct'],
            'items.*.paid_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $items  = collect((array) $this->input('items'));
                $claims = BillingClaim::query()
                    ->where('entity_id', (string) session('selected_entity_id'))
                    ->whereIn('id', $items->pluck('claim_id')->all())
                    ->get(['id', 'code', 'amount'])
                    ->keyBy(fn (BillingClaim $claim): string => (string) $claim->id);

                foreach ($items as $index => $item) {
                    $claim = $claims->get((string) $item['claim_id']);

                    if ($claim === null) {
                        $validator->errors()->add("items.{$index}.claim_id", __('financial_billing.errors.bulk_claims_not_found'));

                        continue;
                    }

                    if ((float) $item['paid_amount'] > (float) $claim->amount) {
                        $validator->errors()->add("items.{$index}.paid_amount", __('financial_billing.errors.bulk_paid_amount_exceeds_claim', ['code' => $claim->code]));
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'items.required'               => __('financial_billing.validation.claims_required'),
            'items.array'                  => __('financial_billing.validation.claims_required'),
            'items.min'                    => __('financial_billing.validation.claims_required'),
            'items.max'                    => __('financial_billing.validation.claims_max', ['max' => BillingBulkReceiptService::MAX_CLAIMS]),
            'items.*.required'             => __('financial_billing.errors.bulk_claims_not_found'),
            'items.*.array'                => __('financial_billing.errors.bulk_claims_not_found'),
            'items.*.claim_id.required'    => __('financial_billing.errors.bulk_claims_not_found'),
            'items.*.claim_id.string'      => __('financial_billing.errors.bulk_claims_not_found'),
            'items.*.claim_id.uuid'        => __('financial_billing.errors.bulk_claims_not_found'),
            'items.*.claim_id.distinct'    => __('financial_billing.validation.claims_distinct'),
            'items.*.paid_amount.required' => __('financial_billing.validation.paid_amount'),
            'items.*.paid_amount.numeric'  => __('financial_billing.validation.paid_amount'),
            'items.*.paid_amount.gt'       => __('financial_billing.validation.paid_amount'),
            'items.*.paid_amount.max'      => __('financial_billing.validation.paid_amount'),
        ];
    }

    /**
     * Valor recebido por guia (id → valor, 2 casas).
     *
     * @return array<string, float>
     */
    public function amounts(): array
    {
        return collect((array) $this->validated('items'))
            ->mapWithKeys(fn (array $item): array => [(string) $item['claim_id'] => round((float) $item['paid_amount'], 2)])
            ->all();
    }

    /** UUID em minúsculas (chaves do mapa de valores = ids do banco). */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $this->merge(['items' => array_map(function ($item) {
            if (is_array($item) && is_string($item['claim_id'] ?? null)) {
                $item['claim_id'] = mb_strtolower(trim($item['claim_id']));
            }

            return $item;
        }, $items)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Models\BillingClaim;
use App\Services\Financial\BillingBatchAttachService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Adicionar guias" / "Incluir em lote": ids das guias de faturamento (UUID,
 * sem repetição, até BillingBatchAttachService::MAX_CLAIMS). A existência é
 * conferida na clínica da sessão numa consulta só (whereIn) — id de outra
 * clínica ou inexistente vira 422 genérico, sem dizer qual. Regras de negócio
 * (rascunho, convênio, lote TISS...) ficam no service, sob lock.
 */
class AttachClaimsToBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_ids'   => ['required', 'array', 'min:1', 'max:' . BillingBatchAttachService::MAX_CLAIMS],
            'claim_ids.*' => ['required', 'string', 'uuid', 'distinct'],
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

                $ids   = array_values(array_unique((array) $this->input('claim_ids')));
                $found = BillingClaim::query()
                    ->where('entity_id', (string) session('selected_entity_id'))
                    ->whereIn('id', $ids)
                    ->count();

                if ($found !== count($ids)) {
                    $validator->errors()->add('claim_ids', __('financial_billing.errors.attach_claims_not_found'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'claim_ids.required'   => __('financial_billing.validation.claims_required'),
            'claim_ids.array'      => __('financial_billing.validation.claims_required'),
            'claim_ids.min'        => __('financial_billing.validation.claims_required'),
            'claim_ids.max'        => __('financial_billing.validation.claims_max', ['max' => BillingBatchAttachService::MAX_CLAIMS]),
            'claim_ids.*.required' => __('financial_billing.errors.attach_claims_not_found'),
            'claim_ids.*.string'   => __('financial_billing.errors.attach_claims_not_found'),
            'claim_ids.*.uuid'     => __('financial_billing.errors.attach_claims_not_found'),
            'claim_ids.*.distinct' => __('financial_billing.validation.claims_distinct'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_billing.attributes');
    }

    /** UUID em minúsculas (a comparação com o banco e as chaves do resultado batem). */
    protected function prepareForValidation(): void
    {
        if (is_array($ids = $this->input('claim_ids'))) {
            $this->merge(['claim_ids' => array_map(fn ($id) => is_string($id) ? mb_strtolower(trim($id)) : $id, $ids)]);
        }
    }
}

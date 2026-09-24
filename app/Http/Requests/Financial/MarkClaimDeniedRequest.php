<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Models\BillingClaim;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MarkClaimDeniedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var BillingClaim $claim */
        $claim = $this->route('claim');

        return [
            'glosa_amount' => [
                'nullable', 'numeric', 'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($claim): void {
                    if ((float) $value > (float) $claim->amount) {
                        $fail(__('financial.billing.glosa_amount_exceeds_claim'));
                    }
                },
            ],
            'glosa_code' => ['nullable', 'string', 'max:30'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}

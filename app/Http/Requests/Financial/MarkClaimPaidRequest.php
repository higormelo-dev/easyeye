<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Models\BillingClaim;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MarkClaimPaidRequest extends FormRequest
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
            'paid_amount' => [
                'nullable', 'numeric', 'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($claim): void {
                    if ((float) $value > (float) $claim->amount) {
                        $fail(__('financial.billing.paid_amount_exceeds_claim'));
                    }
                },
            ],
            'paid_at'        => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];
    }
}

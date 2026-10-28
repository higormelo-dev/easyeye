<?php

namespace App\Http\Requests\Billing;

use App\DTOs\Billing\CheckoutCardInput;
use App\Enums\Billing\CheckoutMethod;
use App\Enums\BillingCycle;
use App\Models\{Plan, PlanPrice};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Contratar (ou trocar plano/ciclo) já pagando. Cartão: só o token do SDK. */
class CheckoutContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id'       => ['required', 'uuid'],
            'billing_cycle' => ['required', 'string', Rule::in(PlanPrice::sellableCycleValues())],
            // Sem forma só na mudança agendada (downgrade de quem já paga):
            // nada é cobrado agora. Nos outros casos o serviço exige a forma.
            'method' => ['nullable', 'string', Rule::in(CheckoutMethod::values())],
            // Número de cartão cru (só dígitos) nunca é aceito — só o token do SDK.
            'card_token'        => ['nullable', 'string', 'max:4096', 'not_regex:/^[\d\s.-]{12,23}$/'],
            'installments'      => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_method_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'issuer_id'         => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
        ];
    }

    public function plan(): Plan
    {
        return Plan::active()->whereKey((string) $this->input('plan_id'))->firstOrFail();
    }

    public function cycle(): BillingCycle
    {
        return BillingCycle::from((string) $this->input('billing_cycle'));
    }

    public function checkoutMethod(): ?CheckoutMethod
    {
        return $this->filled('method') ? CheckoutMethod::from((string) $this->input('method')) : null;
    }

    public function cardInput(): ?CheckoutCardInput
    {
        if (! $this->filled('card_token')) {
            return null;
        }

        return new CheckoutCardInput(
            token: trim((string) $this->input('card_token')),
            installments: (int) ($this->input('installments') ?: 1),
            paymentMethodId: $this->filled('payment_method_id') ? (string) $this->input('payment_method_id') : null,
            issuerId: $this->filled('issuer_id') ? (string) $this->input('issuer_id') : null,
        );
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key') ?: $this->input('idempotency_key');

        return is_string($key) && $key !== '' ? mb_substr($key, 0, 100) : null;
    }
}

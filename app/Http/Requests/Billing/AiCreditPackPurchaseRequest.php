<?php

namespace App\Http\Requests\Billing;

use App\Domains\AI\Services\AiCreditPurchaseService;
use App\DTOs\Billing\CheckoutCardInput;
use App\Enums\Billing\CheckoutMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compra de pacote de créditos de IA pelo checkout (POST
 * /panel/my-subscription/ai-credits): pacote do config, forma de pagamento
 * e, no cartão transparente, só o token do SDK (cartão à vista).
 */
class AiCreditPackPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $codes = collect(app(AiCreditPurchaseService::class)->packages())->pluck('code')->filter()->values()->all();

        return [
            'package_code' => ['required', 'string', Rule::in($codes)],
            'method'       => ['required', 'string', Rule::in(CheckoutMethod::values())],
            // Número de cartão cru (só dígitos) nunca é aceito — só o token do SDK.
            'card_token'        => ['nullable', 'string', 'max:4096', 'not_regex:/^[\d\s.-]{12,23}$/'],
            'installments'      => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_method_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'issuer_id'         => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
        ];
    }

    public function packageCode(): string
    {
        return (string) $this->input('package_code');
    }

    public function checkoutMethod(): CheckoutMethod
    {
        return CheckoutMethod::from((string) $this->input('method'));
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

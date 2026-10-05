<?php

namespace App\Http\Requests\Billing;

use App\DTOs\Billing\CheckoutCardInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cartão do checkout: só o token do SDK JS oficial do gateway (card_token —
 * token do MercadoPago.js/tokenizecard.js, ConfirmationToken/PaymentMethod
 * do Stripe.js ou o cartão criptografado do PagBank, que é longo). Número,
 * CVV e validade nunca são aceitos aqui.
 */
class CheckoutCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Número de cartão cru (só dígitos) nunca é aceito — só o token do SDK.
            'card_token'        => ['required', 'string', 'max:4096', 'not_regex:/^[\d\s.-]{12,23}$/'],
            'installments'      => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_method_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'issuer_id'         => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
        ];
    }

    public function cardInput(): CheckoutCardInput
    {
        return new CheckoutCardInput(
            token: trim((string) $this->input('card_token')),
            installments: (int) ($this->input('installments') ?: 1),
            paymentMethodId: $this->filled('payment_method_id') ? (string) $this->input('payment_method_id') : null,
            issuerId: $this->filled('issuer_id') ? (string) $this->input('issuer_id') : null,
        );
    }

    /** Chave do front (header Idempotency-Key ou campo idempotency_key). */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key') ?: $this->input('idempotency_key');

        return is_string($key) && $key !== '' ? mb_substr($key, 0, 100) : null;
    }
}

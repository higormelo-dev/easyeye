<?php

namespace App\DTOs\Billing;

/**
 * O que o front precisa para tokenizar o cartão no navegador com o SDK JS
 * oficial do gateway (o número do cartão nunca passa pelo EasyEye):
 *
 *  - publicKey: chave PÚBLICA do gateway (nunca a secreta);
 *  - sdkUrl: script oficial a carregar SÓ na tela de checkout;
 *  - tokenization: o que o front manda em card_token —
 *    "card_token" (MercadoPago.js createCardToken / tokenizecard.js do
 *    Pagar.me), "confirmation_token" (Stripe.js createConfirmationToken; pm_
 *    também é aceito) ou "encrypted_card" (PagSeguro.encryptCard do PagBank);
 *  - maxInstallments: teto de parcelas do próprio gateway (o checkout aplica
 *    o menor entre este, o configurado e o ciclo);
 *  - savesCard: o cartão fica guardado no gateway para a renovação.
 */
readonly class CardCheckoutConfigDTO
{
    public function __construct(
        public string $gateway,
        public ?string $publicKey,
        public string $sdkUrl,
        public string $tokenization,
        public int $maxInstallments = 12,
        public bool $savesCard = true,
        public array $extra = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'gateway'          => $this->gateway,
            'public_key'       => $this->publicKey,
            'sdk_url'          => $this->sdkUrl,
            'tokenization'     => $this->tokenization,
            'max_installments' => $this->maxInstallments,
            'saves_card'       => $this->savesCard,
            ...$this->extra,
        ];
    }
}

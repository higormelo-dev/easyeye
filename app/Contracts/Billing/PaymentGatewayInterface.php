<?php

namespace App\Contracts\Billing;

use App\DTOs\Billing\{CancelSubscriptionDTO, CancelSubscriptionResultDTO, CardChargeDTO, CardCheckoutConfigDTO, CreateChargeDTO, CreateChargeResultDTO, CreateSubscriptionDTO, CreateSubscriptionResultDTO, CustomerDTO, GatewayCallContext, GatewayHealthDTO, GatewayWebhookInputDTO, NormalizedWebhookEventDTO, PaymentInstructionsDTO, SaveCardResultDTO};
use App\Exceptions\Billing\GatewayIntegrationException;

interface PaymentGatewayInterface
{
    public function code(): string;

    public function withContext(GatewayCallContext $context): static;

    public function upsertCustomer(CustomerDTO $customer): string;

    /**
     * Recorrência no gateway. `externalSubscriptionId` preenchido quer dizer
     * que o gateway cobra cada ciclo sozinho (recorrência nativa): a renovação
     * local não roda e as cobranças chegam pelo webhook. Gateway que não
     * cobra sozinho (sem recorrência na API, ou com uma que dependeria de o
     * pagador concluir a assinatura) devolve null, e a renovação local
     * (RenewSubscriptionJob) emite a cobrança de cada ciclo.
     */
    public function createSubscription(CreateSubscriptionDTO $payload): CreateSubscriptionResultDTO;

    /**
     * A assinatura criada no gateway já emite a 1ª cobrança (com o vencimento
     * de CreateSubscriptionDTO::$firstDueDate). A ativação então não cria
     * cobrança avulsa — senão o cliente recebe duas cobranças do mesmo
     * período — e a 1ª fatura é ligada pelo webhook dessa cobrança.
     */
    public function subscriptionIssuesFirstCharge(): bool;

    public function cancelSubscription(CancelSubscriptionDTO $payload): CancelSubscriptionResultDTO;

    public function createCharge(CreateChargeDTO $payload): CreateChargeResultDTO;

    public function fetchPayment(string $externalPaymentId): array;

    /**
     * Cancela no gateway uma cobrança emitida e ainda não paga (boleto/Pix/
     * fatura em aberto), para que o cliente não pague o que não vale mais.
     * True = cancelada (ou já estava); false = o gateway não tem como
     * cancelar pela API ou recusou — nesse caso o chamador avisa o time.
     * Nunca lança.
     */
    public function cancelCharge(string $externalPaymentId): bool;

    public function parseWebhook(GatewayWebhookInputDTO $payload): NormalizedWebhookEventDTO;

    /**
     * Chave de idempotência do webhook (webhook_events.external_event_id,
     * única por gateway): a mesma notificação reenviada tem a mesma chave;
     * notificações diferentes do mesmo pedido/cobrança (pago, estorno,
     * chargeback) têm chaves diferentes. Null = só o hash do corpo deduplica.
     */
    public function webhookEventKey(array $payload): ?string;

    public function validateWebhookSignature(GatewayWebhookInputDTO $payload): bool;

    public function healthCheck(): GatewayHealthDTO;

    // ── Checkout transparente ────────────────────────────────────────────────

    /**
     * Formas (CheckoutMethod: pix, boleto, credit_card) que o gateway cobra
     * sem tirar o cliente do EasyEye. Fora daqui, o checkout usa o link da
     * cobrança (payment_url) — InfinitePay inteiro e cartão no Asaas.
     *
     * @return list<string>
     */
    public function transparentMethods(): array;

    /** Cartão: só com a chave pública do SDK configurada. */
    public function supportsTransparent(string $method): bool;

    /** Cobra no servidor o cartão guardado no gateway (renovação). */
    public function chargesSavedCards(): bool;

    /** Troca o cartão da renovação sem cobrar (saveCard). */
    public function supportsCardReplacement(): bool;

    /** A cobrança sem forma definida abre uma página do gateway que aceita cartão (Asaas, InfinitePay, Stripe). */
    public function supportsCardLink(): bool;

    /** Aceita parcelas na cobrança da renovação no cartão salvo (iniciada pelo lojista). */
    public function supportsRenewalInstallments(): bool;

    /**
     * Referências da cadeia de cobranças no cartão (subscriptions.gateway_payload.card)
     * que continuam valendo depois de trocar o cartão (vazio = recomeça).
     *
     * @param array<string, mixed> $references
     *
     * @return array<string, mixed>
     */
    public function cardReferencesAfterReplacement(array $references): array;

    /**
     * Pix (copia-e-cola/QR) ou boleto (linha digitável/PDF) de uma cobrança
     * já emitida. $chargePayload = a resposta guardada da emissão (evita
     * consulta quando ela já traz os dados). Null = a cobrança não tem essa
     * forma (o checkout reemite na forma pedida quando pode).
     *
     * @throws GatewayIntegrationException falha na consulta
     */
    public function paymentInstructions(string $method, string $externalPaymentId, array $chargePayload = []): ?PaymentInstructionsDTO;

    /** Chave pública e SDK JS oficial para tokenizar o cartão; null = sem cartão transparente. */
    public function cardCheckoutConfig(): ?CardCheckoutConfigDTO;

    /**
     * Cria e paga a cobrança no cartão (token do SDK ou cartão salvo), com
     * parcelas e, se pedido, guardando o cartão para a renovação. Recusa =
     * success true com status failed (ou success false com o erro da API).
     */
    public function chargeCard(CardChargeDTO $payload): CreateChargeResultDTO;

    /** Guarda (troca) o cartão da renovação sem cobrar. */
    public function saveCard(string $customerId, string $cardToken, CustomerDTO $payer): SaveCardResultDTO;
}

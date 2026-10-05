<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\Domains\AI\Models\AiCreditPurchase;
use App\Domains\AI\Services\{AiCreditPurchaseService, AiPaywallService};
use App\DTOs\Billing\{CardChargeDTO, CheckoutCardInput, CreateChargeDTO, CreateChargeResultDTO, GatewayCallContext, NormalizedWebhookEventDTO, PaymentInstructionsDTO};
use App\Enums\AI\AiCreditPurchaseStatus;
use App\Enums\Billing\{BillingEventType, CheckoutMethod, InvoiceStatus, PaymentAttemptStatus, PaymentStatus};
use App\Enums\{SubscriptionAccessLevel, SubscriptionBillingMode};
use App\Events\Billing\InvoicePaid;
use App\Exceptions\Billing\{CheckoutException, GatewayIntegrationException, GatewayResolutionException};
use App\Models\Billing\{Gateway, Invoice, Payment, PaymentAttempt};
use App\Models\{Entity, Subscription, User};
use App\Services\SubscriptionService;
use App\Support\Billing\{BillingCustomer, PaymentUrl};
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Throwable;

/**
 * Pacote de créditos de IA pago no MESMO checkout da assinatura (Pix,
 * boleto, cartão à vista; transparente onde o gateway permite, link onde
 * não).
 *
 * Cada compra é um AiCreditPurchase (pendente) + uma Invoice própria
 * (billing_reason = ai_credit_pack, SEM assinatura: fora do ciclo, da régua
 * e da renovação). Pagamento confirmado — cartão aprovado na hora ou webhook
 * (ProcessWebhookEventService delega para applyWebhook) — marca a fatura
 * paga e credita a carteira uma vez só (AiCreditPurchaseService, chave de
 * idempotência por pedido; saldo comprado, que não expira). Estorno e
 * chargeback revertem pela mesma regra do estorno do manager.
 *
 * Quem compra: contato de cobrança (admin, financeiro, dono —
 * billing.contact) com o acesso TOTAL (no limitado a IA está bloqueada;
 * cortesia sem franquia pode comprar) e algum recurso de IA no plano.
 *
 * Gateway: o da assinatura cobrada pelo gateway (o cliente já existe lá);
 * senão o padrão do SaaS (GatewayResolver/manager, depois o
 * billing.default_gateway). Mesma trava por clínica e mesma
 * Idempotency-Key do CheckoutService.
 */
class AiCreditPackCheckoutService
{
    /** Situações da fatura que ainda aceitam pagamento (com o pedido pendente). */
    private const PAYABLE_STATUSES = [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Failed, InvoiceStatus::Cancelled];

    private const PAID_TYPES = ['paid', 'authorized'];

    private const UNPAID_TYPES = ['failed', 'overdue'];

    private const REVERSAL_TYPES = ['refunded', 'chargeback'];

    private bool $holdingLock = false;

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly GatewayResolver $resolver,
        private readonly AiCreditPurchaseService $purchases,
        private readonly AiPaywallService $paywall,
        private readonly SubscriptionService $subscriptions,
        private readonly BillingLogService $billingLog,
        private readonly FinancialEventService $financialEvents,
        private readonly CircuitBreakerService $circuitBreaker,
    ) {
    }

    // ── Leitura ──────────────────────────────────────────────────────────────

    /**
     * Pacotes, se a clínica pode comprar agora (e por quê não), formas de
     * pagamento do gateway (com o valor do pacote, quando informado), as
     * compras recentes e o canal de tempo real.
     *
     * @return array<string, mixed>
     */
    public function options(Entity $entity, ?string $packageCode = null): array
    {
        $reason  = $this->ineligibility($entity);
        $gateway = null;

        if ($reason === null) {
            try {
                $gateway = $this->gatewayFor($entity, $this->subscriptions->currentAccess($entity));
            } catch (CheckoutException) {
                $reason = 'method_unavailable';
            }
        }

        $packages = $this->purchases->packages();
        $package  = $packageCode !== null ? collect($packages)->firstWhere('code', $packageCode) : null;

        return [
            'allowed'   => $reason === null,
            'reason'    => $reason,
            'message'   => $reason !== null ? __("checkout.errors.{$reason}") : null,
            'packages'  => $packages,
            'package'   => $package,
            'payment'   => $gateway ? $this->paymentOptions($gateway, $package ? $package['price_cents'] / 100 : null) : null,
            'purchases' => $this->recentPurchases($entity),
            // Pedidos ainda não pagos (tela de IA): "Continuar pagamento" ou "Descartar".
            'open_orders' => $this->openOrderInvoices($entity)
                ->map(fn (Invoice $invoice) => [...$this->invoiceRow($invoice), 'payment' => $this->invoicePaymentOptions($invoice)])
                ->values()
                ->all(),
            'realtime' => ['channel' => InvoicePaid::channelName((string) $entity->id), 'event' => '.invoice.paid'],
        ];
    }

    /**
     * Pedidos de pacote da clínica ainda em aberto (pendentes de pagamento),
     * do mais antigo ao mais novo.
     *
     * @return Collection<int, Invoice>
     */
    public function openOrderInvoices(Entity $entity): Collection
    {
        return Invoice::query()
            ->where('entity_id', $entity->id)
            ->where('billing_reason', Invoice::BILLING_REASON_AI_CREDIT_PACK)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Invoice $invoice) => $this->isPayable($invoice))
            ->values();
    }

    /** Fatura de pacote da clínica (de outra clínica ou não sendo de pacote: null). */
    public function packInvoiceOf(Entity $entity, string $invoiceId): ?Invoice
    {
        if (! Str::isUuid($invoiceId)) {
            return null;
        }

        $invoice = Invoice::query()->where('entity_id', $entity->id)->whereKey($invoiceId)->first();

        return $invoice?->isAiCreditPack() ? $invoice : null;
    }

    /** A fatura do pacote ainda pode ser paga (pedido pendente, fatura em aberto). */
    public function isPayable(Invoice $invoice): bool
    {
        if (! $invoice->isAiCreditPack() || ! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            return false;
        }

        return $this->purchaseOf($invoice)?->status === AiCreditPurchaseStatus::PendingPayment;
    }

    /**
     * Formas de pagamento da fatura de pacote (para "Pagar" no histórico de
     * Minha assinatura: a clínica em cortesia não tem `payment` no resumo).
     *
     * @return array<string, mixed>|null
     */
    public function invoicePaymentOptions(Invoice $invoice): ?array
    {
        $code = (string) $invoice->gateway_code;

        return $code !== '' && $this->registry->has($code)
            ? $this->paymentOptions($this->registry->get($code), (float) $invoice->amount)
            : null;
    }

    /** @return array<string, mixed> */
    public function invoiceExtra(Invoice $invoice): array
    {
        $purchase = $this->purchaseOf($invoice);

        return [
            'credits'      => (int) ($purchase?->credits ?? data_get($invoice->metadata, 'credits', 0)),
            'package_code' => (string) ($purchase?->package_code ?? data_get($invoice->metadata, 'package_code', '')),
            'package_name' => __('ai.credit_packages.' . ($purchase?->package_code ?? data_get($invoice->metadata, 'package_code', '')) . '.name'),
        ];
    }

    // ── Compra ───────────────────────────────────────────────────────────────

    /**
     * Compra o pacote pagando: abre (ou reaproveita) o pedido e a fatura e
     * já devolve como pagar — instruções do Pix/boleto, resultado do cartão
     * ou o link do gateway.
     *
     * @return array<string, mixed>
     */
    public function purchase(Entity $entity, User $user, string $packageCode, CheckoutMethod $method, ?CheckoutCardInput $card = null, ?string $idempotencyKey = null): array
    {
        return $this->exclusive($entity, 'ai-pack:' . $packageCode . ':' . $method->value, $idempotencyKey, function () use ($entity, $user, $packageCode, $method, $card): array {
            $this->assertEligible($entity);

            $subscription = $this->subscriptions->currentAccess($entity);
            $gateway      = $this->gatewayFor($entity, $subscription);

            $this->assertMethod($gateway, $method, $card);

            [$purchase, $invoice] = $this->openOrder($entity, $user, $subscription, $packageCode, $gateway);

            return [
                'purchase' => $this->purchases->presentPurchase($purchase),
                ...$this->pay($entity, $invoice, $gateway, $method, $card, issue: true),
            ];
        });
    }

    /**
     * Instruções (GET, só leitura) ou emissão (POST) da cobrança da fatura
     * de pacote na forma pedida — mesmo contrato do CheckoutService.
     *
     * @return array<string, mixed>
     */
    public function instructions(Entity $entity, Invoice $invoice, CheckoutMethod $method, bool $issue, ?string $idempotencyKey = null): array
    {
        $run = function () use ($entity, $invoice, $method, $issue): array {
            $invoice = $invoice->fresh();

            if (! $this->isPayable($invoice)) {
                throw CheckoutException::make('invoice_not_payable', 409);
            }

            if ($issue) {
                $this->assertEligible($entity);
            }

            $gateway = $this->invoiceGateway($invoice);
            $this->assertMethod($gateway, $method, null, requireCard: false);

            return $this->pay($entity, $invoice, $gateway, $method, null, $issue);
        };

        return $issue
            ? $this->exclusive($entity, 'ai-pack-issue:' . $invoice->id . ':' . $method->value, $idempotencyKey, $run)
            : $run();
    }

    /** @return array<string, mixed> */
    public function payWithCard(Entity $entity, Invoice $invoice, CheckoutCardInput $card, ?string $idempotencyKey = null): array
    {
        return $this->exclusive($entity, 'ai-pack-card:' . $invoice->id, $idempotencyKey, function () use ($entity, $invoice, $card): array {
            $invoice = $invoice->fresh();

            if (! $this->isPayable($invoice)) {
                throw CheckoutException::make('invoice_not_payable', 409);
            }

            $this->assertEligible($entity);

            $gateway = $this->invoiceGateway($invoice);
            $this->assertMethod($gateway, CheckoutMethod::Card, $card);

            return $this->pay($entity, $invoice, $gateway, CheckoutMethod::Card, $card, issue: true);
        });
    }

    /** @return array<string, mixed> */
    private function pay(Entity $entity, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, ?CheckoutCardInput $card, bool $issue): array
    {
        if ($method->isCard()) {
            if (! $gateway->supportsTransparent($method->value)) {
                return $this->linkFor($entity, $invoice, $gateway, $method, $issue);
            }

            if ($card === null) {
                return ['invoice' => $this->invoiceRow($invoice), ...$this->cardPayload($gateway, (float) $invoice->amount)];
            }

            return $this->chargeCard($entity, $invoice, $gateway, $card);
        }

        if (! $gateway->supportsTransparent($method->value)) {
            return $this->linkFor($entity, $invoice, $gateway, $method, $issue);
        }

        $instructions = $this->resolveInstructions($entity, $invoice, $gateway, $method, $issue);
        $invoice->refresh();

        if ($instructions === null) {
            return ['invoice' => $this->invoiceRow($invoice), 'mode' => 'transparent', 'method' => $method->value, 'instructions' => null, 'issue_required' => true];
        }

        return ['invoice' => $this->invoiceRow($invoice), 'mode' => 'transparent', 'method' => $method->value, 'status' => 'pending', 'instructions' => $instructions->toArray()];
    }

    /**
     * Pedido pendente do mesmo pacote, no mesmo gateway, com a fatura ainda
     * pagável: reaproveitado (abrir e fechar o checkout não empilha pedidos).
     * Senão, pedido + fatura novos.
     *
     * @return array{0: AiCreditPurchase, 1: Invoice}
     */
    private function openOrder(Entity $entity, User $user, ?Subscription $subscription, string $packageCode, PaymentGatewayInterface $gateway): array
    {
        $open = AiCreditPurchase::query()
            ->where('entity_id', $entity->id)
            ->where('package_code', $packageCode)
            ->where('status', AiCreditPurchaseStatus::PendingPayment->value)
            ->whereNotNull('invoice_id')
            ->latest('created_at')
            ->get()
            ->first(function (AiCreditPurchase $purchase) use ($gateway): bool {
                $invoice = $purchase->invoice;

                return $invoice !== null && $invoice->gateway_code === $gateway->code() && $this->isPayable($invoice);
            });

        if ($open !== null) {
            return [$open, $open->invoice];
        }

        try {
            return DB::transaction(function () use ($entity, $user, $subscription, $packageCode, $gateway): array {
                $purchase = $this->purchases->createPendingPurchase(
                    entityId: (string) $entity->id,
                    packageCode: $packageCode,
                    subscriptionId: $subscription?->id ? (string) $subscription->id : null,
                    requestedBy: (string) $user->id,
                    idempotencyKey: 'ai-credit-purchase:checkout:' . Str::uuid(),
                    metadata: ['gateway' => $gateway->code()],
                    source: 'checkout',
                );

                $today   = CarbonImmutable::today();
                $invoice = Invoice::query()->create([
                    'entity_id'       => $entity->id,
                    'subscription_id' => null,
                    'plan_id'         => null,
                    'gateway_id'      => Gateway::query()->where('code', $gateway->code())->value('id'),
                    'gateway_code'    => $gateway->code(),
                    'reference'       => 'IA-' . $today->format('Ymd') . '-' . strtoupper(Str::random(8)),
                    'due_at'          => $today->addDays(max(0, (int) config('billing.first_charge_due_days', 3)))->endOfDay(),
                    'amount'          => round($purchase->amount_cents / 100, 2),
                    'currency'        => $purchase->currency,
                    'status'          => InvoiceStatus::Pending->value,
                    'billing_reason'  => Invoice::BILLING_REASON_AI_CREDIT_PACK,
                    'metadata'        => [
                        'ai_credit_purchase_id' => (string) $purchase->id,
                        'package_code'          => $purchase->package_code,
                        'credits'               => (int) $purchase->credits,
                    ],
                    'correlation_id'  => (string) Str::uuid(),
                    'idempotency_key' => 'ai-credit-pack:' . $purchase->id,
                ]);

                $purchase->update(['invoice_id' => $invoice->id]);

                $this->billingLog->log(
                    level: 'info',
                    message: 'Compra de créditos de IA aberta pelo checkout.',
                    context: ['ai_credit_purchase_id' => $purchase->id, 'package_code' => $packageCode, 'credits' => $purchase->credits],
                    entityId: (string) $entity->id,
                    invoice: $invoice,
                    gatewayCode: $gateway->code(),
                );

                return [$purchase->fresh(), $invoice];
            });
        } catch (DomainException) {
            throw CheckoutException::make('ai_pack_unavailable', 422);
        }
    }

    // ── Descartar / expirar pedido pendente ─────────────────────────────────

    /**
     * A clínica descarta o pedido de pacote ainda não pago (só dela; com
     * pagamento confirmado, 409). Mesma trava por clínica do checkout.
     *
     * @return array<string, mixed>
     */
    public function discardByClinic(Entity $entity, Invoice $invoice, User $user): array
    {
        return $this->exclusive($entity, 'ai-pack-discard:' . $invoice->id, null, function () use ($invoice, $user): array {
            if (! $this->discard($invoice, 'clinic', (string) $user->id)) {
                throw CheckoutException::make('ai_pack_not_discardable', 409);
            }

            return ['invoice' => $this->invoiceRow($invoice->fresh())];
        });
    }

    /**
     * Pedidos de pacote pendentes, sem pagamento nem nova tentativa há mais
     * de ai.credit_purchases.pending_expiry_days dias: descartados (pedido e
     * fatura cancelados; cobranças canceladas no gateway quando possível).
     * Pedidos antigos sem fatura (fluxo manual do manager) ficam.
     *
     * @return array{found: int, expired: int}
     */
    public function expireStaleOrders(bool $dryRun = false): array
    {
        $days   = max(1, (int) config('ai.credit_purchases.pending_expiry_days', 7));
        $cutoff = now()->subDays($days);
        $found  = 0;
        $done   = 0;

        AiCreditPurchase::query()
            ->where('status', AiCreditPurchaseStatus::PendingPayment->value)
            ->whereNotNull('invoice_id')
            ->where('created_at', '<', $cutoff)
            ->with(['invoice', 'entity'])
            ->chunkById(200, function ($purchases) use ($cutoff, $dryRun, &$found, &$done): void {
                foreach ($purchases as $purchase) {
                    $invoice = $purchase->invoice;

                    if ($invoice === null || ! $invoice->isAiCreditPack() || $invoice->status === InvoiceStatus::Paid) {
                        continue;
                    }

                    // Cobrança emitida recentemente (o cliente voltou a pagar): ainda vale.
                    $lastAttempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->max('created_at');

                    if ($lastAttempt !== null && CarbonImmutable::parse($lastAttempt)->greaterThanOrEqualTo($cutoff)) {
                        continue;
                    }

                    $found++;

                    if ($dryRun || $purchase->entity === null) {
                        continue;
                    }

                    try {
                        $expired = $this->exclusive($purchase->entity, 'ai-pack-expire:' . $invoice->id, null, fn (): bool => $this->discard($invoice, 'expired', null));
                    } catch (CheckoutException) {
                        // Clínica ocupada pagando agora: fica para a próxima rodada.
                        $expired = false;
                    }

                    $done += $expired ? 1 : 0;
                }
            });

        return ['found' => $found, 'expired' => $done];
    }

    /**
     * Cancela pedido + fatura do pacote ainda não pago e as cobranças dela
     * (no gateway, depois do commit; a que não cancelar fica registrada e,
     * se paga mesmo assim, credita — o cliente pagou). Eventos de falha ou
     * cancelamento dessas cobranças não reabrem a fatura (applyWebhook).
     *
     * @param 'clinic'|'expired' $reason
     */
    private function discard(Invoice $invoice, string $reason, ?string $userId): bool
    {
        $result = DB::transaction(function () use ($invoice, $reason, $userId): ?array {
            Entity::query()->whereKey($invoice->entity_id)->lockForUpdate()->first();
            $invoice  = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            $purchase = $invoice ? $this->purchaseOf($invoice) : null;

            if ($invoice === null || ! $invoice->isAiCreditPack() || $invoice->status === InvoiceStatus::Paid
                || $purchase?->status !== AiCreditPurchaseStatus::PendingPayment
                || $invoice->payments()->where('status', PaymentStatus::Paid->value)->exists()) {
                return null;
            }

            $live     = $invoice->liveChargeIds();
            $metadata = (array) ($invoice->metadata ?? []);

            $metadata['discarded']         = ['reason' => $reason, 'by' => $userId, 'at' => now()->toIso8601String()];
            $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$live]));

            $this->purchases->cancelPurchase($purchase, $reason === 'expired' ? 'expired_unpaid' : 'discarded_by_clinic', $userId);

            $invoice->forceFill([
                'status'               => InvoiceStatus::Cancelled->value,
                'payment_instructions' => null,
                'metadata'             => $metadata,
            ])->save();

            Payment::query()->where('invoice_id', $invoice->id)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);

            $this->billingLog->log(
                level: 'info',
                message: $reason === 'expired'
                    ? 'Pedido de créditos de IA expirado sem pagamento — pedido e fatura cancelados.'
                    : 'Pedido de créditos de IA descartado pela clínica — pedido e fatura cancelados.',
                context: ['ai_credit_purchase_id' => $purchase->id, 'reason' => $reason, 'by' => $userId, 'charges' => $live],
                entityId: (string) $invoice->entity_id,
                invoice: $invoice,
                gatewayCode: $invoice->gateway_code,
            );

            return [$invoice, $live];
        });

        if ($result === null) {
            return false;
        }

        [$invoice, $live] = $result;

        if ($live !== [] && filled($invoice->gateway_code) && $this->registry->has((string) $invoice->gateway_code)) {
            $gateway = $this->registry->get((string) $invoice->gateway_code)->withContext($this->context($invoice->entity ?? Entity::query()->findOrFail($invoice->entity_id)));

            $this->cancelCharges($gateway, $invoice, $live);
        }

        return true;
    }

    // ── Pix/boleto/link ──────────────────────────────────────────────────────

    private function resolveInstructions(Entity $entity, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $issue): ?PaymentInstructionsDTO
    {
        $cached = $this->cachedInstructions($invoice, $method);

        if ($cached !== null) {
            return $cached;
        }

        if (filled($invoice->external_invoice_id) && $invoice->payment_method === $method->value
            && in_array((string) $invoice->external_invoice_id, $invoice->liveChargeIds(), true)) {
            try {
                $found = $gateway->withContext($this->context($entity))
                    ->paymentInstructions($method->value, (string) $invoice->external_invoice_id, $invoice->raw_gateway_payload ?? []);
            } catch (GatewayIntegrationException) {
                throw CheckoutException::make('gateway_error', 502);
            }

            if ($found !== null && ! $this->expired($found)) {
                $all                 = (array) ($invoice->payment_instructions ?? []);
                $all[$method->value] = [...$found->toArray(), 'charge_id' => $invoice->external_invoice_id];
                $invoice->forceFill(['payment_instructions' => $all])->save();

                return $found;
            }
        }

        if (! $issue) {
            return null;
        }

        $result = $this->issue($entity, $invoice, $gateway, $method, transparent: true);

        if ($result === null) {
            throw CheckoutException::make('charge_pending', 409);
        }

        return $result;
    }

    /**
     * Forma sem transparente (InfinitePay; cartão no Asaas): o link da
     * cobrança vigente, ou — só com $issue (POST) — uma cobrança nova.
     *
     * @return array<string, mixed>
     */
    private function linkFor(Entity $entity, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $issue): array
    {
        if ($method->isCard() && ! $gateway->supportsCardLink()) {
            throw CheckoutException::make('card_unavailable');
        }

        if (filled($invoice->payment_url) && in_array((string) $invoice->external_invoice_id, $invoice->liveChargeIds(), true)) {
            return $this->linkResponse($invoice, $method);
        }

        if (! $issue) {
            return [...$this->linkResponse($invoice, $method), 'issue_required' => true];
        }

        $this->issue($entity, $invoice, $gateway, $method, transparent: false);

        return $this->linkResponse($invoice->refresh(), $method);
    }

    /**
     * Emite a cobrança da fatura no gateway. A cobrança anterior da mesma
     * forma deixa de valer (cancelada no gateway); a de outra forma (Pix ↔
     * boleto) continua valendo — paga uma, as outras são canceladas.
     */
    private function issue(Entity $entity, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $transparent): ?PaymentInstructionsDTO
    {
        $gateway     = $gateway->withContext($this->context($entity));
        $correlation = (string) Str::uuid();
        $attempt     = $this->nextAttemptNumber($invoice);
        $key         = "ai-pack:{$method->value}:{$invoice->id}:{$attempt}";
        $due         = CarbonImmutable::instance($invoice->due_at ?? now())->max(CarbonImmutable::today());

        try {
            $charge = $gateway->createCharge(new CreateChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: '',
                customerId: $this->customerId($gateway, $entity, $invoice),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: $this->description($invoice),
                dueDate: $due->toDateString(),
                paymentMethod: $transparent ? $method->value : null,
                metadata: BillingCustomer::chargeMetadata($entity, ['attempt_number' => $attempt, 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK]),
                idempotencyKey: $key,
            ));
        } catch (GatewayIntegrationException $e) {
            $this->circuitBreaker->recordFailure($gateway->code(), $e->getTriggerType(), (string) $entity->id);
            $this->recordFailedAttempt($invoice, $gateway->code(), $attempt, $key, $correlation, $e->getMessage(), $e->getTriggerType());

            throw CheckoutException::make('gateway_error', 502);
        }

        if (! $charge->success || PaymentStatus::fromGatewayStatus($charge->status)->isUnusable() || blank($charge->externalPaymentId)) {
            $this->recordFailedAttempt($invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, $charge->errorCode);

            throw CheckoutException::make('gateway_error', 502);
        }

        $instructions = $transparent ? $gateway->paymentInstructions($method->value, (string) $charge->externalPaymentId, $charge->rawResponse ?? []) : null;

        [, $stale] = $this->attach($invoice, $gateway->code(), $charge, $method, $attempt, $key, $correlation, $instructions);

        $this->cancelCharges($gateway, $invoice, $stale);

        return $instructions;
    }

    // ── Cartão (à vista) ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function chargeCard(Entity $entity, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutCardInput $card): array
    {
        $gateway     = $gateway->withContext($this->context($entity));
        $correlation = (string) Str::uuid();
        $attempt     = $this->nextAttemptNumber($invoice);
        $key         = "ai-pack:card:{$invoice->id}:{$attempt}";

        try {
            $charge = $gateway->chargeCard(new CardChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: '',
                customerId: $this->customerId($gateway, $entity, $invoice),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: $this->description($invoice),
                payer: BillingCustomer::customer($entity),
                cardToken: $card->token,
                installments: 1,
                saveCard: false,
                idempotencyKey: $key,
                metadata: ['kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK],
                paymentMethodId: $card->paymentMethodId,
                issuerId: $card->issuerId,
            ));
        } catch (GatewayIntegrationException $e) {
            $this->recordFailedAttempt($invoice, $gateway->code(), $attempt, $key, $correlation, $e->getMessage(), $e->getTriggerType());

            throw CheckoutException::make('gateway_error', 502);
        }

        if (! $charge->success) {
            $this->recordFailedAttempt($invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, $charge->errorCode);

            throw CheckoutException::make('gateway_error', 502);
        }

        if (PaymentStatus::fromGatewayStatus($charge->status)->isUnusable()) {
            $this->recordFailedAttempt($invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, 'declined', $charge);

            throw $this->declined($charge->errorMessage);
        }

        [$invoice, $stale] = $this->attach($invoice, $gateway->code(), $charge, CheckoutMethod::Card, $attempt, $key, $correlation);

        $this->cancelCharges($gateway, $invoice, $stale);

        $paid = $invoice->status === InvoiceStatus::Paid;

        return [
            'invoice'     => $this->invoiceRow($invoice),
            'mode'        => 'transparent',
            'method'      => CheckoutMethod::Card->value,
            'status'      => $paid ? 'paid' : ($charge->nextAction ? 'requires_action' : 'pending'),
            'next_action' => $charge->nextAction,
            'card'        => null,
        ];
    }

    /**
     * A nova cobrança passa a ser a vigente da fatura (tentativa, Payment,
     * ids). Paga na hora: credita e as outras cobranças deixam de valer;
     * Pix/boleto: só a anterior da mesma forma; cartão pendente: nenhuma.
     *
     * @return array{0: Invoice, 1: list<string>}
     */
    private function attach(Invoice $invoice, string $gatewayCode, CreateChargeResultDTO $charge, CheckoutMethod $method, int $attempt, string $key, string $correlation, ?PaymentInstructionsDTO $instructions = null): array
    {
        return DB::transaction(function () use ($invoice, $gatewayCode, $charge, $method, $attempt, $key, $correlation, $instructions): array {
            Entity::query()->whereKey($invoice->entity_id)->lockForUpdate()->first();
            $invoice   = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $gatewayId = Gateway::query()->where('code', $gatewayCode)->value('id');
            $status    = PaymentStatus::fromGatewayStatus($charge->status);
            $newId     = (string) $charge->externalPaymentId;
            $previous  = $invoice->external_invoice_id;

            $attemptRow = PaymentAttempt::query()->create([
                'entity_id'        => $invoice->entity_id,
                'subscription_id'  => null,
                'invoice_id'       => $invoice->id,
                'gateway_id'       => $gatewayId,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attempt,
                'status'           => PaymentAttemptStatus::Succeeded->value,
                'trigger'          => 'checkout',
                'request_payload'  => ['invoice_id' => $invoice->id, 'method' => $method->value, 'installments' => 1, 'charge_idempotency_key' => $key, 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK],
                'response_payload' => $charge->rawResponse,
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $key,
                'correlation_id'   => $correlation,
            ]);

            $payment = Payment::query()->where('gateway_code', $gatewayCode)->where('external_payment_id', $newId)->first()
                ?? Payment::query()->create([
                    'entity_id'           => $invoice->entity_id,
                    'invoice_id'          => $invoice->id,
                    'subscription_id'     => null,
                    'gateway_id'          => $gatewayId,
                    'gateway_code'        => $gatewayCode,
                    'external_payment_id' => $newId,
                    'status'              => PaymentStatus::Pending->value,
                    'amount'              => $charge->amount ?? (float) $invoice->amount,
                    'currency'            => $invoice->currency,
                    'payment_method'      => $method->value,
                    'raw_gateway_payload' => $charge->rawResponse,
                    'idempotency_key'     => 'payment-' . $key,
                    'correlation_id'      => $correlation,
                    'metadata'            => ['source' => 'checkout', 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK],
                ]);

            $attemptRow->update(['payment_id' => $payment->id]);

            // O webhook DESTA mesma cobrança confirmou antes de o gateway
            // responder (corrida do cartão aprovado): já está tudo lançado —
            // nada a estornar nem a cancelar.
            if ($invoice->status === InvoiceStatus::Paid && $invoice->isSettledByCharge($newId, $payment)) {
                $this->billingLog->log(
                    level: 'info',
                    message: 'Pacote de IA: pagamento já confirmado pelo webhook desta mesma cobrança.',
                    context: ['external_charge_id' => $newId, 'method' => $method->value],
                    entityId: (string) $invoice->entity_id,
                    invoice: $invoice,
                    payment: $payment,
                    gatewayCode: $gatewayCode,
                    correlationId: $correlation,
                );

                return [$invoice, []];
            }

            // Paga enquanto o gateway respondia (webhook de outra cobrança):
            // a nova deixa de valer (pendente: cancelar; paga: alerta).
            if ($invoice->status === InvoiceStatus::Paid) {
                $this->billingLog->log(
                    level: $status === PaymentStatus::Paid ? 'critical' : 'warning',
                    message: $status === PaymentStatus::Paid
                        ? 'Pacote de IA já pago recebeu outro pagamento — estornar no gateway.'
                        : 'Cobrança emitida para pacote de IA já pago — cancelada no gateway quando possível.',
                    context: ['external_charge_id' => $newId, 'method' => $method->value],
                    entityId: (string) $invoice->entity_id,
                    invoice: $invoice,
                    payment: $payment,
                    gatewayCode: $gatewayCode,
                    correlationId: $correlation,
                );

                return [$invoice, $status === PaymentStatus::Paid ? [] : [$newId]];
            }

            $entries  = (array) ($invoice->payment_instructions ?? []);
            $live     = $invoice->liveChargeIds($newId);
            $metadata = (array) ($invoice->metadata ?? []);
            $keep     = [];

            if ($status !== PaymentStatus::Paid) {
                if ($method->isCard()) {
                    $keep = $live;
                } else {
                    foreach ($entries as $entryMethod => $entry) {
                        $id = (string) data_get($entry, 'charge_id', '');

                        if ($id !== '' && $entryMethod !== $method->value && in_array($id, $live, true)) {
                            $keep[] = $id;
                        }
                    }
                }
            }

            $stale   = array_values(array_diff($live, $keep));
            $entries = array_filter($entries, fn ($entry) => in_array((string) data_get($entry, 'charge_id', ''), $keep, true));

            if ($instructions !== null) {
                $entries[$method->value] = [...$instructions->toArray(), 'charge_id' => $newId];
            }

            if (filled($previous) && $previous !== $newId) {
                $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $previous]));
            }

            if ($stale !== []) {
                $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$stale]));

                Payment::query()->where('gateway_code', $gatewayCode)->whereIn('external_payment_id', $stale)
                    ->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Cancelled->value]);
            }

            $invoice->forceFill([
                'gateway_id'           => $gatewayId ?? $invoice->gateway_id,
                'gateway_code'         => $gatewayCode,
                'external_invoice_id'  => $newId,
                'payment_method'       => $method->value,
                'payment_instructions' => $entries !== [] ? $entries : null,
                'payment_url'          => PaymentUrl::safe($charge->paymentUrl) ?? ($instructions?->paymentUrl ?: $invoice->payment_url),
                'raw_gateway_payload'  => $charge->rawResponse,
                'status'               => InvoiceStatus::Pending->value,
                'metadata'             => $metadata,
            ])->save();

            if ($status === PaymentStatus::Paid) {
                $this->confirm($invoice, $payment, 'checkout', $correlation);
            }

            return [$invoice->refresh(), $stale];
        });
    }

    // ── Confirmação, estorno e webhook ───────────────────────────────────────

    /**
     * Pagamento confirmado (dentro da transação, fatura travada): fatura e
     * pagamento pagos, créditos na carteira (uma vez só), evento financeiro
     * e o aviso em tempo real. Fatura já paga: nada muda.
     */
    public function confirm(Invoice $invoice, ?Payment $payment, string $source, string $correlationId): bool
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            return false;
        }

        $paidAt = now();

        if ($payment && $payment->status !== PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::Paid->value, 'paid_at' => $paidAt, 'invoice_id' => $invoice->id]);
        }

        // A cobrança que quitou (estorno dela reverte; o de outra é duplicidade).
        $metadata = (array) ($invoice->metadata ?? []);

        if (filled($payment?->external_payment_id)) {
            $metadata['paid_by_charge'] = (string) $payment->external_payment_id;
        }

        $invoice->update(['status' => InvoiceStatus::Paid->value, 'paid_at' => $paidAt, 'metadata' => $metadata]);

        $purchase = $this->purchaseOf($invoice);

        if ($purchase !== null) {
            $purchase = $this->purchases->creditFromGatewayPayment($purchase, [
                'invoice_id' => (string) $invoice->id,
                'payment_id' => $payment?->id ? (string) $payment->id : null,
                'gateway'    => $invoice->gateway_code,
                'source'     => $source,
            ]);
        }

        $this->financialEvents->record(
            eventType: BillingEventType::PaymentSucceeded,
            entityId: (string) $invoice->entity_id,
            invoice: $invoice,
            payment: $payment,
            amount: (float) ($payment?->amount ?? $invoice->amount),
            currency: $payment?->currency ?? $invoice->currency,
            metadata: ['kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK, 'ai_credit_purchase_id' => $purchase?->id, 'credits' => $purchase?->credits],
            correlationId: $correlationId,
            source: $source,
        );

        $this->billingLog->log(
            level: $purchase === null ? 'critical' : 'info',
            message: $purchase === null
                ? 'Pacote de IA pago sem o pedido ligado à fatura — créditos NÃO lançados; conferir e creditar pelo manager.'
                : 'Pacote de IA pago — créditos lançados na carteira.',
            context: ['ai_credit_purchase_id' => $purchase?->id, 'credits' => $purchase?->credits, 'source' => $source],
            entityId: (string) $invoice->entity_id,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $invoice->gateway_code,
            correlationId: $correlationId,
        );

        event(InvoicePaid::fromInvoice($invoice));

        return true;
    }

    /**
     * Evento do gateway de uma cobrança de pacote (ProcessWebhookEventService
     * delega para cá, na transação dele). Nunca toca a assinatura.
     *
     * @return array{0: Invoice, 1: ?Payment, 2: string, 3: list<string>}
     */
    public function applyWebhook(NormalizedWebhookEventDTO $n, string $eventType, Invoice $invoice, string $correlationId): array
    {
        Entity::query()->whereKey($invoice->entity_id)->lockForUpdate()->first();
        $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $payment = filled($n->externalPaymentId)
            ? Payment::query()->where('gateway_code', $n->gatewayCode)->where('external_payment_id', $n->externalPaymentId)->first()
            : null;
        $isPaid     = in_array($eventType, self::PAID_TYPES, true);
        $isReversal = in_array($eventType, self::REVERSAL_TYPES, true);

        // Estorno/chargeback já aplicado a este pagamento (reentrega depois
        // da retenção de webhook_events): nada de novo evento financeiro/log.
        if ($isReversal && $payment !== null && $payment->status === ($eventType === 'refunded' ? PaymentStatus::Refunded : PaymentStatus::Chargeback)) {
            return [$invoice, $payment, 'duplicate', []];
        }

        // Estorno da cobrança que QUITOU o pacote: segue o estorno normal
        // (reverte créditos), mesmo que ela esteja entre as desligadas.
        $settledByThis = $isReversal && $invoice->isSettledByCharge($n->externalPaymentId, $payment);

        // Estorno do pagamento em DUPLICIDADE (o pacote foi quitado por outra
        // cobrança): só esse pagamento — créditos, pedido e fatura ficam.
        if ($isReversal && ! $settledByThis && $invoice->isSettledByAnotherCharge($n->externalPaymentId)) {
            return $this->reverseDuplicatePayment($n, $eventType, $invoice, $payment, $correlationId);
        }

        // Pedido descartado (pela clínica ou expirado): falha, vencimento ou
        // cancelamento das cobranças dele não reabrem a fatura. Pagamento
        // tardio vale (credita — o cliente pagou) e estorno segue normal.
        if (! $isPaid && ! $isReversal && $invoice->status === InvoiceStatus::Cancelled && filled(data_get($invoice->metadata, 'discarded'))) {
            if ($payment && $payment->status !== PaymentStatus::Paid) {
                $payment->update(['status' => PaymentStatus::Cancelled->value]);
            }

            return [$invoice, $payment, 'ignored_discarded_order', []];
        }

        // Cobrança substituída (outra forma) ou recusa de cartão: só o pagamento dela vale.
        $detached = (array) data_get($invoice->metadata, 'detached_charges', []);

        if (! $isPaid && ! $settledByThis && filled($n->externalPaymentId) && $n->externalPaymentId !== $invoice->external_invoice_id && in_array($n->externalPaymentId, $detached, true)) {
            if (in_array($eventType, [...self::UNPAID_TYPES, 'payment_cancelled'], true) && $payment && $payment->status !== PaymentStatus::Paid) {
                $payment->update(['status' => PaymentStatus::Cancelled->value]);
            }

            return [$invoice, $payment, 'ignored_replaced_charge', []];
        }

        $chargeEvent = in_array($eventType, [...self::PAID_TYPES, ...self::UNPAID_TYPES, 'payment_cancelled', 'refunded', 'chargeback', 'created'], true);

        if (! $payment && $chargeEvent && filled($n->externalPaymentId) && ! ($isPaid && $invoice->status === InvoiceStatus::Paid)) {
            $payment = Payment::query()->create([
                'entity_id'           => $invoice->entity_id,
                'invoice_id'          => $invoice->id,
                'subscription_id'     => null,
                'gateway_code'        => $n->gatewayCode,
                'external_payment_id' => $n->externalPaymentId,
                'status'              => PaymentStatus::Pending->value,
                'amount'              => $n->amount ?? (float) $invoice->amount,
                'currency'            => $n->currency ?? $invoice->currency,
                'idempotency_key'     => 'webhook:' . $n->gatewayCode . ':' . $n->externalPaymentId,
                'metadata'            => ['source' => 'webhook', 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK],
            ]);
        }

        if ($chargeEvent) {
            $updates = [];

            if (filled($n->externalPaymentId) && blank($invoice->external_invoice_id)
                && ! Invoice::query()->where('gateway_code', $n->gatewayCode)->where('external_invoice_id', $n->externalPaymentId)->exists()) {
                $updates['external_invoice_id'] = $n->externalPaymentId;
            }

            if (($url = PaymentUrl::safe($n->paymentUrl)) !== null) {
                $updates['payment_url'] = $url;
            }

            if ($updates !== []) {
                $invoice->update($updates);
            }
        }

        if ($isPaid) {
            if ($payment?->status === PaymentStatus::Paid) {
                return [$invoice, $payment, 'duplicate', []];
            }

            if ($invoice->status === InvoiceStatus::Paid) {
                $this->billingLog->log(
                    level: 'warning',
                    message: 'Pagamento para pacote de IA já pago — conferir duplicidade no gateway.',
                    context: ['external_payment_id' => $n->externalPaymentId, 'amount' => $n->amount],
                    entityId: (string) $invoice->entity_id,
                    invoice: $invoice,
                    payment: $payment,
                    gatewayCode: $n->gatewayCode,
                    correlationId: $correlationId,
                );

                return [$invoice, $payment, 'alert_invoice_already_paid', []];
            }

            $this->confirm($invoice, $payment, 'webhook', $correlationId);

            return [$invoice->refresh(), $payment?->refresh(), 'ai_credits_granted', $this->retireOtherCharges($invoice->refresh(), (string) $n->externalPaymentId)];
        }

        if (in_array($eventType, self::UNPAID_TYPES, true)) {
            if ($payment?->status === PaymentStatus::Paid || $invoice->status === InvoiceStatus::Paid) {
                return [$invoice, $payment, 'stale', []];
            }

            $payment?->update(['status' => PaymentStatus::Failed->value, 'failed_at' => now()]);
            $invoice->update(['status' => $eventType === 'overdue' ? InvoiceStatus::Overdue->value : InvoiceStatus::Failed->value]);

            return [$invoice, $payment, 'ai_pack_unpaid', []];
        }

        if ($eventType === 'payment_cancelled') {
            if ($payment?->status === PaymentStatus::Paid || $invoice->status === InvoiceStatus::Paid) {
                return [$invoice, $payment, 'stale', []];
            }

            $payment?->update(['status' => PaymentStatus::Cancelled->value]);
            $invoice->update(['status' => InvoiceStatus::Cancelled->value]);

            return [$invoice, $payment, 'payment_cancelled', []];
        }

        if (in_array($eventType, ['refunded', 'chargeback'], true)) {
            $refund = $eventType === 'refunded';

            $payment?->update($refund
                ? ['status' => PaymentStatus::Refunded->value, 'refunded_at' => now()]
                : ['status' => PaymentStatus::Chargeback->value, 'chargeback_at' => now()]);
            $invoice->update(['status' => $refund ? InvoiceStatus::Refunded->value : InvoiceStatus::Failed->value]);

            $purchase = $this->purchaseOf($invoice);

            if ($purchase !== null) {
                $this->purchases->reverseFromGateway($purchase, $refund ? 'gateway_refund' : 'gateway_chargeback');
            }

            $this->financialEvents->record(
                eventType: $refund ? BillingEventType::PaymentRefunded : BillingEventType::ChargebackReceived,
                entityId: (string) $invoice->entity_id,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : (float) $invoice->amount,
                currency: $payment?->currency ?? $invoice->currency,
                metadata: ['kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK, 'ai_credit_purchase_id' => $purchase?->id],
                correlationId: $correlationId,
                source: 'webhook',
            );

            $this->billingLog->log(
                level: 'warning',
                message: $refund ? 'Pacote de IA estornado no gateway — créditos revertidos.' : 'Chargeback de pacote de IA — créditos revertidos (o que já foi consumido não volta).',
                context: ['ai_credit_purchase_id' => $purchase?->id, 'credits' => $purchase?->credits],
                entityId: (string) $invoice->entity_id,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $n->gatewayCode,
                correlationId: $correlationId,
            );

            return [$invoice, $payment, $refund ? 'ai_credits_refunded' : 'ai_credits_chargeback', []];
        }

        return [$invoice, $payment, $eventType === 'created' ? 'charge_synced' : 'ignored', []];
    }

    /**
     * Estorno/chargeback do pagamento em duplicidade (o pacote já foi quitado
     * por outra cobrança, que segue paga): registra só esse pagamento —
     * Payment, evento financeiro e log. Créditos, pedido e fatura ficam.
     *
     * @return array{0: Invoice, 1: ?Payment, 2: string, 3: list<string>}
     */
    private function reverseDuplicatePayment(NormalizedWebhookEventDTO $n, string $eventType, Invoice $invoice, ?Payment $payment, string $correlationId): array
    {
        $refund = $eventType === 'refunded';

        $payment ??= filled($n->externalPaymentId) ? Payment::query()->create([
            'entity_id'           => $invoice->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => null,
            'gateway_code'        => $n->gatewayCode,
            'external_payment_id' => $n->externalPaymentId,
            'status'              => PaymentStatus::Pending->value,
            'amount'              => $n->amount ?? (float) $invoice->amount,
            'currency'            => $n->currency ?? $invoice->currency,
            'idempotency_key'     => 'webhook:' . $n->gatewayCode . ':' . $n->externalPaymentId,
            'metadata'            => ['source' => 'webhook', 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK, 'duplicate_payment' => true],
        ]) : null;

        $payment?->update($refund
            ? ['status' => PaymentStatus::Refunded->value, 'refunded_at' => now()]
            : ['status' => PaymentStatus::Chargeback->value, 'chargeback_at' => now()]);

        $paidBy = data_get($invoice->metadata, 'paid_by_charge');

        $this->financialEvents->record(
            eventType: $refund ? BillingEventType::PaymentRefunded : BillingEventType::ChargebackReceived,
            entityId: (string) $invoice->entity_id,
            invoice: $invoice,
            payment: $payment,
            amount: $payment ? (float) $payment->amount : $n->amount,
            currency: $payment?->currency ?? $n->currency ?? $invoice->currency,
            metadata: ['kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK, 'duplicate_payment' => true, 'paid_by_charge' => $paidBy],
            correlationId: $correlationId,
            source: 'webhook',
        );

        $this->billingLog->log(
            level: 'warning',
            message: $refund
                ? 'Estorno do pagamento em duplicidade do pacote de IA — créditos e pedido mantidos (o pagamento que quitou segue pago).'
                : 'Chargeback do pagamento em duplicidade do pacote de IA — créditos e pedido mantidos (o pagamento que quitou segue pago).',
            context: ['external_payment_id' => $n->externalPaymentId, 'paid_by_charge' => $paidBy],
            entityId: (string) $invoice->entity_id,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $n->gatewayCode,
            correlationId: $correlationId,
        );

        return [$invoice, $payment, $refund ? 'ai_pack_duplicate_refunded' : 'ai_pack_duplicate_chargeback', []];
    }

    /**
     * Paga por uma das cobranças: as outras que ainda valiam deixam de valer
     * (devolvidas para cancelar no gateway depois do commit).
     *
     * @return list<string>
     */
    private function retireOtherCharges(Invoice $invoice, string $paidChargeId): array
    {
        if ($paidChargeId === '') {
            return [];
        }

        $stale    = $invoice->liveChargeIds($paidChargeId);
        $metadata = (array) ($invoice->metadata ?? []);

        if (filled($invoice->external_invoice_id) && $invoice->external_invoice_id !== $paidChargeId) {
            $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $invoice->external_invoice_id]));
        }

        $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$stale]));
        $metadata['paid_by_charge']    = $paidChargeId;

        $invoice->forceFill(['payment_instructions' => null, 'metadata' => $metadata])->save();

        if ($stale !== []) {
            Payment::query()->where('invoice_id', $invoice->id)->whereIn('external_payment_id', $stale)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);
        }

        return $stale;
    }

    // ── Regras ───────────────────────────────────────────────────────────────

    /**
     * Por que a clínica não pode comprar agora (null = pode): acesso total
     * (no limitado a IA está bloqueada; com o bloqueio desligado na chave de
     * emergência, basta ter acesso), algum recurso de IA no plano e pacotes
     * configurados.
     */
    public function ineligibility(Entity $entity): ?string
    {
        if (! $entity->is_client) {
            return 'not_client';
        }

        $level = $this->subscriptions->currentAccess($entity)?->accessLevel() ?? SubscriptionAccessLevel::None;
        $ok    = config('billing.enforce_subscription_access', true)
            ? $level === SubscriptionAccessLevel::Full
            : $level !== SubscriptionAccessLevel::None;

        if (! $ok) {
            return 'ai_pack_requires_full_access';
        }

        if (! $this->paywall->hasAnyAiFeature((string) $entity->id) || $this->purchases->packages() === []) {
            return 'ai_pack_unavailable';
        }

        return null;
    }

    private function assertEligible(Entity $entity): void
    {
        $reason = $this->ineligibility($entity);

        if ($reason !== null) {
            throw CheckoutException::make($reason, $reason === 'not_client' ? 404 : 403);
        }
    }

    private function assertMethod(PaymentGatewayInterface $gateway, CheckoutMethod $method, ?CheckoutCardInput $card, bool $requireCard = true): void
    {
        if (! $method->isCard()) {
            return;
        }

        if (! $gateway->supportsTransparent($method->value)) {
            if (! $gateway->supportsCardLink()) {
                throw CheckoutException::make('card_unavailable');
            }

            return;
        }

        if ($requireCard && ($card === null || trim($card->token) === '')) {
            throw CheckoutException::make('card_token_required');
        }

        // Pacote de créditos: cartão só à vista.
        if ($card !== null && $card->installments > 1) {
            throw CheckoutException::make('installments_invalid');
        }
    }

    /**
     * O gateway da assinatura cobrada pelo gateway (o cliente já existe lá);
     * senão o padrão do SaaS.
     */
    private function gatewayFor(Entity $entity, ?Subscription $subscription): PaymentGatewayInterface
    {
        if ($subscription !== null && $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && filled($subscription->gateway) && $this->registry->has((string) $subscription->gateway)) {
            return $this->registry->get((string) $subscription->gateway);
        }

        if ($subscription?->plan !== null) {
            try {
                return $this->resolver->resolveForNewPurchase($entity, $subscription->plan);
            } catch (GatewayResolutionException) {
                // cai no padrão da configuração
            }
        }

        $code = (string) config('billing.default_gateway');

        if ($code === '' || ! $this->registry->has($code)) {
            throw CheckoutException::make('method_unavailable');
        }

        return $this->registry->get($code);
    }

    private function invoiceGateway(Invoice $invoice): PaymentGatewayInterface
    {
        $code = (string) $invoice->gateway_code;

        if ($code === '' || ! $this->registry->has($code)) {
            throw CheckoutException::make('method_unavailable');
        }

        return $this->registry->get($code);
    }

    private function purchaseOf(Invoice $invoice): ?AiCreditPurchase
    {
        $id = data_get($invoice->metadata, 'ai_credit_purchase_id');

        return (is_string($id) && Str::isUuid($id) ? AiCreditPurchase::query()->find($id) : null)
            ?? AiCreditPurchase::query()->where('invoice_id', $invoice->id)->first();
    }

    private function customerId(PaymentGatewayInterface $gateway, Entity $entity, Invoice $invoice): string
    {
        $stored = data_get($invoice->metadata, 'gateway_customer_id');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromSubscription = Subscription::query()
            ->forEntity((string) $entity->id)
            ->where('gateway', $gateway->code())
            ->whereNotNull('gateway_customer_id')
            ->latest('created_at')
            ->value('gateway_customer_id');

        $customerId = filled($fromSubscription)
            ? (string) $fromSubscription
            : $gateway->upsertCustomer(BillingCustomer::customer($entity));

        $invoice->forceFill(['metadata' => [...(array) ($invoice->metadata ?? []), 'gateway_customer_id' => $customerId]])->save();

        return $customerId;
    }

    private function description(Invoice $invoice): string
    {
        return __('ai.credit_purchase_description', ['credits' => (int) data_get($invoice->metadata, 'credits', 0)]) . " — {$invoice->reference}";
    }

    private function cachedInstructions(Invoice $invoice, CheckoutMethod $method): ?PaymentInstructionsDTO
    {
        $data     = (array) data_get($invoice->payment_instructions, $method->value, []);
        $chargeId = $data['charge_id'] ?? null;

        if ($data === [] || blank($chargeId) || ! in_array($chargeId, $invoice->liveChargeIds(), true)) {
            return null;
        }

        $dto = new PaymentInstructionsDTO(
            method: $method->value,
            pix: $method === CheckoutMethod::Pix ? ($data['pix'] ?? null) : null,
            boleto: $method === CheckoutMethod::Boleto ? ($data['boleto'] ?? null) : null,
            paymentUrl: $data['payment_url'] ?? null,
        );

        return $this->expired($dto) ? null : $dto;
    }

    private function expired(PaymentInstructionsDTO $instructions): bool
    {
        $expires = $instructions->pix['expires_at'] ?? null;

        if (! is_string($expires) || $expires === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($expires)->isPast();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $ids */
    private function cancelCharges(PaymentGatewayInterface $gateway, Invoice $invoice, array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            try {
                $cancelled = $gateway->cancelCharge($id);
            } catch (Throwable) {
                $cancelled = false;
            }

            if (! $cancelled) {
                $this->billingLog->log(
                    level: 'warning',
                    message: 'Pacote de IA: cobrança que deixou de valer não pôde ser cancelada no gateway (expira sozinha ou cancelar manualmente).',
                    context: ['external_charge_id' => $id],
                    entityId: (string) $invoice->entity_id,
                    invoice: $invoice,
                    gatewayCode: $gateway->code(),
                );
            }
        }
    }

    private function recordFailedAttempt(Invoice $invoice, string $gatewayCode, int $attempt, string $key, string $correlation, string $error, ?string $errorCode, ?CreateChargeResultDTO $charge = null): void
    {
        DB::transaction(function () use ($invoice, $gatewayCode, $attempt, $key, $correlation, $error, $errorCode, $charge): void {
            PaymentAttempt::query()->create([
                'entity_id'        => $invoice->entity_id,
                'subscription_id'  => null,
                'invoice_id'       => $invoice->id,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attempt,
                'status'           => PaymentAttemptStatus::Failed->value,
                'trigger'          => 'checkout',
                'request_payload'  => ['invoice_id' => $invoice->id, 'charge_idempotency_key' => $key, 'kind' => Invoice::BILLING_REASON_AI_CREDIT_PACK],
                'response_payload' => $charge?->rawResponse,
                'error_code'       => $errorCode !== null ? mb_substr($errorCode, 0, 80) : null,
                'error_message'    => mb_substr($error, 0, 2000),
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $key,
                'correlation_id'   => $correlation,
            ]);

            if (filled($charge?->externalPaymentId) && $charge->externalPaymentId !== $invoice->external_invoice_id) {
                $locked   = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $metadata = (array) ($locked->metadata ?? []);

                $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $charge->externalPaymentId]));
                $locked->forceFill(['metadata' => $metadata])->save();
            }
        });
    }

    private function nextAttemptNumber(Invoice $invoice): int
    {
        return (int) PaymentAttempt::query()->where('invoice_id', $invoice->id)->max('attempt_number') + 1;
    }

    private function declined(?string $reason): CheckoutException
    {
        $reason = trim((string) $reason);

        return $reason !== '' && ! str_starts_with($reason, 'HTTP ') && ! str_starts_with($reason, '[') && mb_strlen($reason) <= 200
            ? CheckoutException::make('card_declined', 422, ['reason' => $reason])
            : CheckoutException::make('card_declined_generic');
    }

    private function context(Entity $entity): GatewayCallContext
    {
        return new GatewayCallContext((string) Str::uuid(), (string) $entity->id);
    }

    // ── Respostas ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function paymentOptions(PaymentGatewayInterface $gateway, ?float $amount): array
    {
        $methods = collect(CheckoutMethod::cases())
            ->reject(fn (CheckoutMethod $method) => $method->isCard() && ! $gateway->supportsTransparent($method->value) && ! $gateway->supportsCardLink())
            ->map(fn (CheckoutMethod $method) => [
                'method' => $method->value,
                'label'  => __("checkout.methods.{$method->value}"),
                'mode'   => $gateway->supportsTransparent($method->value) ? 'transparent' : 'link',
            ])->values()->all();

        return ['gateway' => $gateway->code(), 'methods' => $methods, ...$this->cardPayload($gateway, (float) ($amount ?? 0))];
    }

    /** @return array<string, mixed> */
    private function cardPayload(PaymentGatewayInterface $gateway, float $amount): array
    {
        $config = $gateway->supportsTransparent(CheckoutMethod::Card->value) ? $gateway->cardCheckoutConfig() : null;

        if ($config === null) {
            return ['mode' => 'link', 'method' => CheckoutMethod::Card->value, 'card' => null];
        }

        return [
            'mode'   => 'transparent',
            'method' => CheckoutMethod::Card->value,
            'card'   => [
                ...$config->toArray(),
                // Pacote: à vista e sem guardar o cartão.
                'max_installments' => 1,
                'saves_card'       => false,
                'installments'     => [['count' => 1, 'amount' => round($amount, 2), 'total' => round($amount, 2), 'interest_free' => true]],
                'saved_card'       => null,
                'renewal_in_full'  => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function linkResponse(Invoice $invoice, CheckoutMethod $method): array
    {
        return ['invoice' => $this->invoiceRow($invoice), 'mode' => 'link', 'method' => $method->value, 'payment_url' => $invoice->payment_url];
    }

    /** @return array<string, mixed> */
    public function invoiceRow(Invoice $invoice): array
    {
        $payable = $this->isPayable($invoice);

        return [
            'id'             => (string) $invoice->id,
            'reference'      => $invoice->reference,
            'amount'         => (float) $invoice->amount,
            'currency'       => $invoice->currency,
            'status'         => $invoice->status?->value,
            'due_date'       => $invoice->due_at?->toDateString(),
            'paid_at'        => $invoice->paid_at?->toIso8601String(),
            'created_at'     => $invoice->created_at?->toIso8601String(),
            'period_start'   => null,
            'period_end'     => null,
            'payment_method' => $invoice->payment_method,
            'payment_url'    => $payable ? $invoice->payment_url : null,
            'can_pay'        => $payable,
            // Pedido ainda não pago: a clínica pode descartar (some de "em aberto").
            'can_discard'    => $payable,
            'kind'           => Invoice::BILLING_REASON_AI_CREDIT_PACK,
            'plan_change'    => null,
            'ai_credit_pack' => $this->invoiceExtra($invoice),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentPurchases(Entity $entity): array
    {
        return AiCreditPurchase::query()
            ->where('entity_id', $entity->id)
            ->with('invoice')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (AiCreditPurchase $purchase) => [
                ...$this->purchases->presentPurchase($purchase),
                'invoice' => $purchase->invoice ? $this->invoiceRow($purchase->invoice) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Mesma trava por clínica e mesma Idempotency-Key do CheckoutService (um
     * pagamento por vez na clínica; o pedido repetido devolve o resultado).
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function exclusive(Entity $entity, string $scope, ?string $idempotencyKey, Closure $callback): mixed
    {
        $resultKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? 'billing:checkout:result:' . $entity->id . ':' . hash('sha256', $scope . '|' . $idempotencyKey)
            : null;

        if ($this->holdingLock) {
            return $callback();
        }

        if ($resultKey !== null && is_array($cached = Cache::get($resultKey))) {
            return $cached;
        }

        $lock = Cache::lock('billing:checkout:entity:' . $entity->id, 90);

        try {
            return $lock->block(max(0, (int) config('billing.checkout.lock_wait_seconds', 10)), function () use ($resultKey, $callback): mixed {
                if ($resultKey !== null && is_array($cached = Cache::get($resultKey))) {
                    return $cached;
                }

                $this->holdingLock = true;

                try {
                    $result = $callback();
                } finally {
                    $this->holdingLock = false;
                }

                if ($resultKey !== null && is_array($result)) {
                    Cache::put($resultKey, $result, now()->addMinutes(max(1, (int) config('billing.checkout.idempotency_minutes', 30))));
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            Log::notice('Checkout de pacote de IA: clínica ocupada com outro pagamento.', ['entity_id' => $entity->id]);

            throw CheckoutException::make('busy', 409);
        }
    }
}

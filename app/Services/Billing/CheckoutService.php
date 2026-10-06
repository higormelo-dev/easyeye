<?php

namespace App\Services\Billing;

use App\Contracts\Billing\{PaymentGatewayInterface, QueriesGatewayRecurrences};
use App\DTOs\Billing\{CardChargeDTO, CardCheckoutConfigDTO, CheckoutCardInput, CheckoutPayment, CreateChargeDTO, CreateChargeResultDTO, GatewayCallContext, GatewayRecurrenceChargeDTO, PaymentInstructionsDTO, SavedCardDTO};
use App\Enums\Billing\{CheckoutMethod, InvoiceStatus, PaymentAttemptStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Events\Billing\InvoicePaid;
use App\Exceptions\Billing\{CheckoutException, GatewayIntegrationException, GatewayResolutionException, SubscriptionSupersededException};
use App\Models\Billing\{Gateway, Invoice, Payment, PaymentAttempt};
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting};
use App\Support\Billing\{BillingCustomer, ChargeIdempotency, PlanPricing};
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Throwable;

/**
 * Checkout transparente: a clínica paga a assinatura do EasyEye sem sair do
 * sistema (tela "Minha assinatura", aviso de pagamento, /subscription/expired
 * e o cadastro no site).
 *
 *  - Pix/boleto: instruções (copia-e-cola/QR, linha digitável/PDF) da
 *    cobrança em aberto. A leitura (GET) nunca emite cobrança: sem cobrança
 *    na forma pedida, devolve issue_required e o front pede a emissão por
 *    POST (issueCharge, com o limite de pagamento). A emissão numa forma
 *    nova mantém a cobrança da outra forma valendo (instruções das duas
 *    guardadas): alternar entre Pix e boleto não reemite. Paga uma, as
 *    outras são canceladas (só na renovação local — a recorrência do Asaas
 *    emite as cobranças dela, que já aceitam Pix e boleto);
 *  - cartão: token do SDK JS oficial do gateway (o número nunca passa por
 *    aqui), parcelas sem juros no ciclo anual, cartão guardado no gateway
 *    para a renovação (RenewSubscriptionJob cobra sozinho);
 *  - cartão no Asaas: Asaas Checkout (página hospedada — HostedCheckoutService):
 *    a clínica vai ao ambiente seguro do Asaas e volta para Minha assinatura;
 *    fatura de período vira assinatura no cartão (troca segura da recorrência);
 *  - gateway/forma sem transparente nem checkout hospedado (InfinitePay): o
 *    link da cobrança (payment_url).
 *
 * Isolamento: toda fatura é procurada pela clínica da sessão (de outra
 * clínica = 404). Idempotência: trava por clínica (uma operação de pagamento
 * por vez, com as regras relidas dentro dela) e a chave do front
 * (Idempotency-Key) devolve o mesmo resultado por
 * billing.checkout.idempotency_minutes. Troca de plano de quem já tem um
 * plano pago e vigente: upgrade/downgrade (PlanChangeService). Chamadas ao gateway ficam fora de
 * transação; o resultado é gravado com a empresa e a assinatura travadas
 * (mesma ordem do SubscriptionCycleService).
 *
 * Pagamento confirmado → SubscriptionCycleService::confirmPayment, que
 * transmite App\Events\Billing\InvoicePaid no canal billing.{entityId}.
 */
class CheckoutService
{
    /** Faturas que o cliente pode pagar (Cancelled só a vigente: Pix expirado). */
    private const PAYABLE_STATUSES = [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Failed];

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly GatewayResolver $resolver,
        private readonly BillingSubscriptionOrchestrator $orchestrator,
        private readonly SubscriptionCycleService $cycles,
        private readonly BillingLogService $billingLog,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly PlanChangeService $planChanges,
        private readonly AiCreditPackCheckoutService $aiPacks,
        private readonly HostedCheckoutService $hosted,
    ) {
    }

    /** Esta instância já segura a trava da clínica (chamadas internas não travam de novo). */
    private bool $holdingLock = false;

    // ── Leitura ──────────────────────────────────────────────────────────────

    /**
     * Tudo que a tela "Minha assinatura" mostra: assinatura (plano, ciclo,
     * situação, cartão salvo, mudança agendada), faturas, faturas em aberto,
     * formas de pagamento do gateway, planos para trocar e o canal de tempo
     * real.
     *
     * @return array<string, mixed>
     */
    public function summary(Entity $entity): array
    {
        $this->assertClient($entity);

        $current  = $this->currentSubscription($entity);
        $billable = $current && $this->isBillable($current) ? $current : null;
        $gateway  = $billable ? $this->gatewayFor($billable) : null;

        $invoices = Invoice::query()
            ->where('entity_id', $entity->id)
            ->whereNotIn('status', [InvoiceStatus::Draft->value])
            ->where(fn ($q) => $q->whereNull('billing_reason')->orWhere('billing_reason', '!=', SubscriptionCycleService::BILLING_REASON_UNAPPLIED))
            ->orderByDesc('due_at')
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        $open = $billable ? $this->payableInvoices($billable) : collect();

        // Pedidos de pacote de créditos de IA em aberto (fatura sem
        // assinatura): pagos pelo mesmo checkout, com as formas do gateway do
        // pacote. Listados à parte (open_ai_packs) — não são dívida da
        // assinatura (sem "vencida", fora de open_invoices).
        $openPacks = $this->aiPacks->openOrderInvoices($entity);

        $row = fn (Invoice $invoice, bool $payable) => $invoice->isAiCreditPack()
            ? [...$this->aiPacks->invoiceRow($invoice), 'payment' => $payable ? $this->aiPacks->invoicePaymentOptions($invoice) : null]
            : $this->invoiceRow($invoice, $payable);

        return [
            'subscription'  => $current ? $this->subscriptionRow($current, $billable !== null) : null,
            'open_invoices' => $open->map(fn (Invoice $invoice) => $row($invoice, true))->values()->all(),
            'open_ai_packs' => $openPacks->map(fn (Invoice $invoice) => $row($invoice, true))->values()->all(),
            'invoices'      => $invoices->map(fn (Invoice $invoice) => $row($invoice, $open->contains('id', $invoice->id) || $openPacks->contains('id', $invoice->id)))->values()->all(),
            'payment'       => $gateway ? $this->gatewayOptions($gateway, $billable) : null,
            'plans'         => $this->sellablePlans(),
            'checkout'      => $this->checkoutSettings(),
            'realtime'      => $this->realtime($entity),
        ];
    }

    /**
     * Formas de pagamento para contratar (plano/ciclo): gateway que será
     * usado, o que é transparente, a configuração do cartão e as parcelas.
     * Quem já tem um plano pago e vigente vê a troca (`change`): upgrade com
     * o valor proporcional cobrado agora, ou a data em que a mudança vale.
     *
     * @return array<string, mixed>
     */
    public function contractOptions(Entity $entity, Plan $plan, BillingCycle $cycle): array
    {
        $this->assertClient($entity);

        $amount  = $this->priceOrFail($plan, $cycle);
        $current = $this->currentSubscription($entity);

        if (PlanChangeService::isPaidInForce($current)) {
            if ($this->isSameTerms($current, $plan, $cycle)) {
                return [
                    'plan'     => ['id' => (string) $plan->id, 'name' => $plan->name],
                    'cycle'    => $cycle->value,
                    'amount'   => $amount,
                    'change'   => ['type' => 'current'],
                    'methods'  => [],
                    'realtime' => $this->realtime($entity),
                ];
            }

            $quote   = $this->planChanges->quote($current, $plan, $cycle, $amount);
            $gateway = $this->gatewayFor($current);

            return [
                'plan'   => ['id' => (string) $plan->id, 'name' => $plan->name],
                'cycle'  => $cycle->value,
                'amount' => $quote['type'] === PlanChangeService::TYPE_UPGRADE ? $quote['amount_now'] : $amount,
                'change' => $quote,
                ...($quote['type'] === PlanChangeService::TYPE_UPGRADE
                    ? $this->gatewayOptions($gateway, null, (float) $quote['amount_now'], $cycle)
                    : ['gateway' => $gateway->code(), 'methods' => [], 'mode' => 'none', 'card' => null]),
                'realtime' => $this->realtime($entity),
            ];
        }

        $gateway = $this->newPurchaseGateway($entity, $plan);

        return [
            'plan'   => ['id' => (string) $plan->id, 'name' => $plan->name],
            'cycle'  => $cycle->value,
            'amount' => $amount,
            ...$this->gatewayOptions($gateway, null, $amount, $cycle),
            'realtime' => $this->realtime($entity),
        ];
    }

    /**
     * Como pagar uma fatura em aberto na forma pedida — SÓ leitura (GET):
     * nunca emite nem cancela cobrança. Sem cobrança nessa forma (ou Pix
     * vencido), `issue_required` — a emissão é pelo POST issueCharge.
     *
     * @return array<string, mixed>
     */
    public function instructions(Entity $entity, string $invoiceId, CheckoutMethod $method): array
    {
        if (($pack = $this->aiPackInvoice($entity, $invoiceId)) !== null) {
            return $this->aiPacks->instructions($entity, $pack, $method, issue: false);
        }

        [$subscription, $invoice] = $this->payableInvoiceOf($entity, $invoiceId);

        return $this->instructionsFor($subscription, $invoice, $method, issue: false);
    }

    /**
     * Emite (ou reemite) a cobrança da fatura na forma pedida — POST, com o
     * limite de tentativas de pagamento. Cobrança já existente nessa forma é
     * devolvida sem emitir de novo.
     *
     * @return array<string, mixed>
     */
    public function issueCharge(Entity $entity, string $invoiceId, CheckoutMethod $method, ?string $idempotencyKey = null): array
    {
        if (($pack = $this->aiPackInvoice($entity, $invoiceId)) !== null) {
            return $this->aiPacks->instructions($entity, $pack, $method, issue: true, idempotencyKey: $idempotencyKey);
        }

        return $this->exclusive($entity, 'issue:' . $invoiceId . ':' . $method->value, $idempotencyKey, function () use ($entity, $invoiceId, $method): array {
            [$subscription, $invoice] = $this->payableInvoiceOf($entity, $invoiceId);

            return $this->instructionsFor($subscription, $invoice, $method, issue: true);
        });
    }

    /** @return array<string, mixed> */
    private function instructionsFor(Subscription $subscription, Invoice $invoice, CheckoutMethod $method, bool $issue): array
    {
        $gateway = $this->gatewayFor($subscription);

        if ($method->isCard()) {
            if (! $gateway->supportsTransparent($method->value)) {
                if ($gateway->supportsHostedCardCheckout()) {
                    return $this->hostedFor($subscription, $invoice, $gateway, $issue);
                }

                if (! $gateway->supportsCardLink()) {
                    throw CheckoutException::make('card_unavailable');
                }

                return $this->linkFor($subscription, $invoice, $gateway, $method, $issue);
            }

            return [
                'invoice' => $this->invoiceRow($invoice, true),
                ...$this->cardPayload($gateway, $subscription, (float) $invoice->amount, $this->invoiceCycle($subscription, $invoice)),
            ];
        }

        if (! $gateway->supportsTransparent($method->value)) {
            return $this->linkFor($subscription, $invoice, $gateway, $method, $issue);
        }

        $instructions = $this->resolveInstructions($subscription, $invoice, $gateway, $method, $issue);

        if ($instructions === null) {
            return [
                'invoice'        => $this->invoiceRow($invoice->refresh(), true),
                'mode'           => 'transparent',
                'method'         => $method->value,
                'instructions'   => null,
                'issue_required' => true,
            ];
        }

        return [
            'invoice'      => $this->invoiceRow($invoice->refresh(), true),
            'mode'         => 'transparent',
            'method'       => $method->value,
            'instructions' => $instructions->toArray(),
        ];
    }

    // ── Pagamentos ───────────────────────────────────────────────────────────

    /**
     * Paga uma fatura em aberto no cartão (token do SDK do gateway). O cartão
     * fica guardado para a renovação.
     *
     * @return array<string, mixed>
     */
    public function payInvoiceWithCard(Entity $entity, string $invoiceId, CheckoutCardInput $card, ?string $idempotencyKey = null): array
    {
        if (($pack = $this->aiPackInvoice($entity, $invoiceId)) !== null) {
            return $this->aiPacks->payWithCard($entity, $pack, $card, $idempotencyKey);
        }

        return $this->exclusive($entity, 'card:' . $invoiceId, $idempotencyKey, function () use ($entity, $invoiceId, $card): array {
            [$subscription, $invoice] = $this->payableInvoiceOf($entity, $invoiceId);

            return $this->chargeInvoiceCard($entity, $subscription, $invoice, $card);
        });
    }

    /** @return array<string, mixed> */
    private function chargeInvoiceCard(Entity $entity, Subscription $subscription, Invoice $invoice, CheckoutCardInput $card): array
    {
        $gateway = $this->gatewayFor($subscription);

        if (! $gateway->supportsTransparent(CheckoutMethod::Card->value)) {
            if ($gateway->supportsHostedCardCheckout()) {
                return $this->hostedFor($subscription, $invoice, $gateway, issue: true);
            }

            if (! $gateway->supportsCardLink()) {
                throw CheckoutException::make('card_unavailable');
            }

            return $this->linkFor($subscription, $invoice, $gateway, CheckoutMethod::Card, issue: true);
        }

        $installments = $this->validInstallments($card->installments, $gateway, (float) $invoice->amount, $this->invoiceCycle($subscription, $invoice));
        $correlation  = (string) Str::uuid();
        $attempt      = $this->nextAttemptNumber($invoice);
        $key          = "checkout:card:{$invoice->id}:{$attempt}";
        $gateway      = $gateway->withContext(new GatewayCallContext($correlation, (string) $entity->id));

        try {
            $charge = $gateway->chargeCard(new CardChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: $this->customerId($gateway, $subscription, $entity),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Fatura {$invoice->reference}",
                payer: BillingCustomer::customer($entity),
                cardToken: $card->token,
                installments: $installments,
                saveCard: true,
                idempotencyKey: $key,
                metadata: ['cycle_months' => $this->invoiceCycle($subscription, $invoice)?->months()],
                paymentMethodId: $card->paymentMethodId,
                issuerId: $card->issuerId,
            ));
        } catch (GatewayIntegrationException $e) {
            $this->recordFailedAttempt($subscription, $invoice, $gateway->code(), $attempt, $key, $correlation, $e->getMessage(), $e->getTriggerType());

            throw CheckoutException::make('gateway_error', 502);
        }

        return $this->applyCardCharge($subscription, $invoice, $gateway, $charge, $attempt, $key, $correlation, $installments);
    }

    /**
     * Troca o cartão da renovação sem cobrar (Stripe: SetupIntent — 3DS volta
     * em next_action e o front chama de novo com a referência seti_).
     *
     * @return array<string, mixed>
     */
    public function replaceCard(Entity $entity, string $token, ?string $idempotencyKey = null): array
    {
        return $this->exclusive($entity, 'replace-card', $idempotencyKey, function () use ($entity, $token): array {
            $subscription = $this->billableSubscription($entity);
            $gateway      = $this->gatewayFor($subscription);

            if (! $gateway->supportsTransparent(CheckoutMethod::Card->value)) {
                throw CheckoutException::make('card_unavailable');
            }

            if (! $gateway->supportsCardReplacement()) {
                throw CheckoutException::make('card_change_unsupported');
            }

            $gateway = $gateway->withContext(new GatewayCallContext((string) Str::uuid(), (string) $entity->id));

            try {
                $result = $gateway->saveCard($this->customerId($gateway, $subscription, $entity), $token, BillingCustomer::customer($entity));
            } catch (GatewayIntegrationException) {
                throw CheckoutException::make('gateway_error', 502);
            }

            if ($result->nextAction !== null) {
                return ['status' => 'requires_action', 'next_action' => $result->nextAction];
            }

            if (! $result->success || $result->card === null) {
                throw $this->declined($result->errorMessage);
            }

            DB::transaction(function () use ($subscription, $result, $gateway): void {
                $locked  = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                $payload = $locked->gateway_payload ?? [];
                // Cartão novo: só fica o que o gateway pede manter da cadeia
                // de cobranças (Mercado Pago: o id da 1ª cobrança da
                // assinatura) — a referência do cartão anterior sai.
                $kept = $gateway->cardReferencesAfterReplacement((array) ($payload['card'] ?? []));

                if ($kept === []) {
                    unset($payload['card']);
                } else {
                    $payload['card'] = $kept;
                }

                $locked->update([
                    ...$this->cardColumns($result->card, max(1, (int) $locked->card_installments)),
                    'gateway_payload' => $payload,
                ]);
            });

            $this->billingLog->log(
                level: 'info',
                message: 'Cartão da renovação trocado pelo checkout.',
                context: ['brand' => $result->card->brand, 'last4' => $result->card->last4],
                entityId: (string) $entity->id,
                subscription: $subscription,
                gatewayCode: $gateway->code(),
            );

            return ['status' => 'saved', 'card' => ['brand' => $result->card->brand, 'last4' => $result->card->last4]];
        });
    }

    /**
     * Contrata (ou troca de plano/ciclo) já pagando. Tudo dentro da trava da
     * clínica (regras relidas nela):
     *
     *  - plano pago e vigente: upgrade (paga a diferença proporcional; muda
     *    quando pago) ou downgrade (agendado para o fim do período, nada
     *    cobrado agora) — PlanChangeService;
     *  - contratação ainda aguardando o 1º pagamento do MESMO plano e ciclo:
     *    reaproveitada (paga a fatura dela em vez de abrir outra);
     *  - senão: nova contratação (BillingSubscriptionOrchestrator).
     *
     * @return array<string, mixed>
     */
    public function contract(Entity $entity, Plan $plan, BillingCycle $cycle, ?CheckoutMethod $method, ?CheckoutCardInput $card = null, ?string $idempotencyKey = null): array
    {
        $this->assertClient($entity);
        $amount = $this->priceOrFail($plan, $cycle);

        return $this->exclusive($entity, 'contract', $idempotencyKey, function () use ($entity, $plan, $cycle, $method, $card, $amount): array {
            $current = $this->currentSubscription($entity);

            if (PlanChangeService::isPaidInForce($current)) {
                if ($this->isSameTerms($current, $plan, $cycle)) {
                    throw CheckoutException::make('already_active', 409);
                }

                return $this->changePlan($entity, $current, $plan, $cycle, $method, $card, $amount);
            }

            if ($method === null) {
                throw CheckoutException::make('method_unavailable');
            }

            $pending = $this->pendingContract($current, $plan, $cycle);

            if ($pending !== null && ($invoice = $this->payableInvoices($pending)->first()) !== null) {
                if ($method->isCard()) {
                    if ($card === null) {
                        throw CheckoutException::make('card_token_required');
                    }

                    return ['subscription' => $this->subscriptionRow($pending, true), ...$this->chargeInvoiceCard($entity, $pending, $invoice, $card)];
                }

                return ['subscription' => $this->subscriptionRow($pending, true), ...$this->instructionsFor($pending, $invoice, $method, issue: true)];
            }

            $this->assertNotAlreadyActive($entity, $plan, $cycle);

            return $this->activate($entity, $plan, $cycle, $method, $card, $amount);
        });
    }

    /** @return array<string, mixed> */
    private function activate(Entity $entity, Plan $plan, BillingCycle $cycle, CheckoutMethod $method, ?CheckoutCardInput $card, float $amount): array
    {
        $gateway = $this->newPurchaseGateway($entity, $plan);
        $useCard = $method->isCard() && $gateway->supportsTransparent($method->value);

        if ($method->isCard() && ! $useCard && ! $gateway->supportsHostedCardCheckout() && ! $gateway->supportsCardLink()) {
            throw CheckoutException::make('card_unavailable');
        }

        if ($useCard) {
            if ($card === null || trim($card->token) === '') {
                throw CheckoutException::make('card_token_required');
            }

            $card = new CheckoutCardInput(
                token: $card->token,
                installments: $this->validInstallments($card->installments, $gateway, $amount, $cycle),
                paymentMethodId: $card->paymentMethodId,
                issuerId: $card->issuerId,
            );
        }

        $checkout = new CheckoutPayment($method, $useCard ? $card : null);

        try {
            $subscription = $this->orchestrator->activateWithGateway($entity, $plan, $cycle, $gateway->code(), $checkout);
        } catch (GatewayIntegrationException $e) {
            if ($e->getTriggerType() === BillingSubscriptionOrchestrator::TRIGGER_CARD_DECLINED) {
                throw $this->declined($checkout->outcome->declineMessage);
            }

            Log::warning('Checkout: contratação falhou no gateway.', ['entity_id' => $entity->id, 'error' => $e->getMessage()]);

            throw CheckoutException::make('gateway_error', 502);
        } catch (SubscriptionSupersededException) {
            throw CheckoutException::make('busy', 409);
        }

        $invoice = $subscription->currentInvoice;
        $result  = ['subscription' => $this->subscriptionRow($subscription, true), 'invoice' => $invoice ? $this->invoiceRow($invoice, true) : null];

        if ($useCard) {
            $paid = $invoice?->status === InvoiceStatus::Paid;

            return [
                ...$result,
                'mode'        => 'transparent',
                'method'      => $method->value,
                'status'      => $paid ? 'paid' : ($checkout->outcome->nextAction ? 'requires_action' : 'pending'),
                'next_action' => $checkout->outcome->nextAction,
                'card'        => $checkout->outcome->savedCard ? ['brand' => $checkout->outcome->savedCard->brand, 'last4' => $checkout->outcome->savedCard->last4] : null,
            ];
        }

        $resolved = $this->resolveGateway((string) $subscription->gateway);

        // Cartão no checkout hospedado (Asaas Checkout): a 1ª fatura é paga na
        // página do Asaas e vira a assinatura no cartão.
        if ($invoice !== null && $method->isCard() && ! $resolved->supportsTransparent($method->value) && $resolved->supportsHostedCardCheckout()) {
            return [...$result, ...$this->hostedFor($subscription->loadMissing(['entity', 'plan']), $invoice, $resolved, issue: true)];
        }

        if ($invoice === null || ! $resolved->supportsTransparent($method->value)) {
            return [...$result, ...($invoice ? $this->linkResponse($invoice, $method) : ['mode' => 'link', 'method' => $method->value, 'payment_url' => null])];
        }

        try {
            $instructions = $this->resolveInstructions($subscription, $invoice, $resolved, $method, issue: true);
        } catch (CheckoutException $e) {
            // Contratada: a cobrança existe, só as instruções ainda não
            // (Asaas: a 1ª parcela chega pelo webhook). O front pede de novo.
            return [...$result, 'mode' => 'transparent', 'method' => $method->value, 'status' => 'pending', 'instructions' => null, 'retry' => $e->errorCode];
        }

        if ($instructions === null) {
            return [...$result, 'mode' => 'transparent', 'method' => $method->value, 'status' => 'pending', 'instructions' => null, 'retry' => 'charge_pending'];
        }

        return [
            ...$result,
            'invoice'      => $this->invoiceRow($invoice->refresh(), true),
            'mode'         => 'transparent',
            'method'       => $method->value,
            'status'       => 'pending',
            'instructions' => $instructions->toArray(),
        ];
    }

    /**
     * Troca de plano/ciclo de quem já tem um plano pago e vigente (decisão
     * do dono do produto — PlanChangeService): upgrade cobra só a diferença
     * proporcional e muda quando pago; downgrade agenda para o fim do
     * período pago, sem cobrar agora. A assinatura paga nunca é cancelada.
     *
     * @return array<string, mixed>
     */
    private function changePlan(Entity $entity, Subscription $current, Plan $plan, BillingCycle $cycle, ?CheckoutMethod $method, ?CheckoutCardInput $card, float $amount): array
    {
        $quote       = $this->planChanges->quote($current, $plan, $cycle, $amount);
        $correlation = (string) Str::uuid();

        if ($quote['type'] === PlanChangeService::TYPE_SCHEDULED) {
            $subscription = $this->planChanges->schedule($current, $plan, $cycle, $quote, $correlation);

            return [
                'subscription' => $this->subscriptionRow($subscription, true),
                'change'       => $quote,
                'invoice'      => null,
                'mode'         => 'none',
                'method'       => null,
                'status'       => 'scheduled',
            ];
        }

        if ($method === null) {
            throw CheckoutException::make('method_unavailable');
        }

        $gateway = $this->gatewayFor($current);

        if ($method->isCard()) {
            if ($gateway->supportsTransparent($method->value)) {
                if ($card === null || trim($card->token) === '') {
                    throw CheckoutException::make('card_token_required');
                }

                $this->validInstallments($card->installments, $gateway, (float) $quote['amount_now'], $cycle);
            } elseif (! $gateway->supportsHostedCardCheckout() && ! $gateway->supportsCardLink()) {
                throw CheckoutException::make('card_unavailable');
            }
        }

        $due = $method->isCard() ? CarbonImmutable::today() : $this->cycles->firstDueDate();

        [$invoice, $toCancel] = $this->planChanges->upgradeInvoice($current, $plan, $cycle, $quote, $due, $correlation);
        $this->planChanges->cancelCharges($current, $toCancel, $correlation);

        $base = ['subscription' => $this->subscriptionRow($current->fresh(['plan', 'entity']), true), 'change' => $quote];

        if ($method->isCard() && $card !== null && $gateway->supportsTransparent($method->value)) {
            return [...$base, ...$this->chargeInvoiceCard($entity, $current, $invoice, $card)];
        }

        return [...$base, ...$this->instructionsFor($current, $invoice, $method, issue: true)];
    }

    // ── Instruções Pix/boleto ────────────────────────────────────────────────

    /**
     * Instruções da cobrança na forma pedida: as guardadas (de qualquer
     * cobrança ainda valendo da fatura), as da cobrança vigente (consulta ao
     * gateway) e, só com $issue (POST), uma cobrança nova nessa forma. Sem
     * $issue e sem cobrança na forma: null (issue_required).
     */
    private function resolveInstructions(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $issue): ?PaymentInstructionsDTO
    {
        $gateway        = $gateway->withContext(new GatewayCallContext((string) Str::uuid(), (string) $subscription->entity_id));
        $fromRecurrence = $subscription->hasGatewayRecurrence() && ! $invoice->isPlanChange();

        // Cobrança da recorrência do gateway (Asaas) ainda não ligada à
        // fatura (o webhook não chegou): procura na recorrência.
        if (blank($invoice->external_invoice_id) && $fromRecurrence) {
            $this->linkRecurrenceCharge($subscription, $invoice, $gateway);
            $invoice->refresh();
        }

        $cached = $this->cachedInstructions($invoice, $method);

        if ($cached !== null) {
            return $cached;
        }

        if (filled($invoice->external_invoice_id) && in_array($invoice->payment_method, [null, $method->value], true)) {
            try {
                $found = $gateway->paymentInstructions($method->value, (string) $invoice->external_invoice_id, $invoice->raw_gateway_payload ?? []);
            } catch (GatewayIntegrationException $e) {
                // Erro do gateway (credencial, limite, fora do ar) — não é
                // "forma indisponível": a tela pede para tentar de novo.
                Log::warning('Checkout: instruções de pagamento não geradas pelo gateway.', ['invoice_id' => $invoice->id, 'gateway' => $gateway->code(), 'trigger' => $e->getTriggerType()]);

                throw CheckoutException::make('instructions_failed', 503);
            }

            if ($found !== null && ! $this->expired($found)) {
                $this->storeInstructions($invoice, $method, $found);

                return $found;
            }
        }

        // Recorrência do gateway: as cobranças são dela — não reemite.
        if ($fromRecurrence) {
            throw blank($invoice->external_invoice_id)
                ? CheckoutException::make('charge_pending', 409)
                : CheckoutException::make('method_unavailable');
        }

        if (! $issue) {
            return null;
        }

        return $this->reissue($subscription, $invoice, $gateway, $method, transparent: true);
    }

    /**
     * Forma sem transparente (InfinitePay; cartão no Asaas/Stripe): o link da
     * cobrança. Fatura sem cobrança ainda (ex.: upgrade) — só com $issue —
     * emite a cobrança padrão do gateway para ter o link.
     *
     * @return array<string, mixed>
     */
    private function linkFor(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $issue): array
    {
        if (filled($invoice->payment_url) || filled($invoice->external_invoice_id) || ($subscription->hasGatewayRecurrence() && ! $invoice->isPlanChange())) {
            return $this->linkResponse($invoice, $method);
        }

        if (! $issue) {
            return [...$this->linkResponse($invoice, $method), 'issue_required' => true];
        }

        $this->reissue($subscription, $invoice, $gateway->withContext(new GatewayCallContext((string) Str::uuid(), (string) $subscription->entity_id)), $method, transparent: false);

        return $this->linkResponse($invoice->refresh(), $method);
    }

    /**
     * Nova cobrança da fatura (renovação local ou upgrade) — só com a trava
     * da clínica (POST). Cobrança anterior da MESMA forma (ou de cartão) é
     * desligada da fatura e cancelada no gateway; a de outra forma (Pix ↔
     * boleto) continua valendo com as instruções guardadas — alternar não
     * reemite, e a paga primeiro cancela as outras. Vence no vencimento da
     * fatura (ou hoje, se já passou).
     */
    private function reissue(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway, CheckoutMethod $method, bool $transparent): ?PaymentInstructionsDTO
    {
        $invoice->refresh();

        if (! $this->isPayable($subscription, $invoice)) {
            throw CheckoutException::make('invoice_not_payable', 409);
        }

        // Outra requisição já emitiu nesta forma enquanto esta esperava a trava.
        if ($transparent && ($cached = $this->cachedInstructions($invoice, $method)) !== null) {
            return $cached;
        }

        $entity      = $subscription->entity;
        $correlation = (string) Str::uuid();
        $attempt     = $this->nextAttemptNumber($invoice);
        $key         = "checkout:{$method->value}:{$invoice->id}:{$attempt}";
        $due         = CarbonImmutable::instance($invoice->due_at ?? now())->max(CarbonImmutable::today());

        try {
            $charge = $gateway->createCharge(new CreateChargeDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: (string) $subscription->id,
                customerId: $this->customerId($gateway, $subscription, $entity),
                amount: (float) $invoice->amount,
                currency: (string) $invoice->currency,
                description: "Fatura {$invoice->reference}",
                dueDate: $due->toDateString(),
                paymentMethod: $transparent ? $method->value : null,
                metadata: BillingCustomer::chargeMetadata($entity, ['attempt_number' => $attempt]),
                idempotencyKey: $key,
                // Tentativa anterior sem resposta definitiva: o gateway pode
                // ter criado a cobrança — reaproveita em vez de emitir outra.
                knownChargeIds: $invoice->knownChargeIds(),
                lookupBeforeCreate: ChargeIdempotency::previousAttemptInconclusive($invoice),
            ));
        } catch (GatewayIntegrationException $e) {
            $this->circuitBreaker->recordFailure($gateway->code(), $e->getTriggerType(), (string) $entity->id);
            $this->recordFailedAttempt($subscription, $invoice, $gateway->code(), $attempt, $key, $correlation, $e->getMessage(), $e->getTriggerType());

            throw CheckoutException::make('gateway_error', 502);
        }

        if (! $charge->success || PaymentStatus::fromGatewayStatus($charge->status)->isUnusable() || blank($charge->externalPaymentId)) {
            $this->recordFailedAttempt($subscription, $invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, $charge->errorCode);

            Log::warning('Checkout: emissão da cobrança recusada pelo gateway.', [
                'invoice_id' => $invoice->id,
                'gateway'    => $gateway->code(),
                'method'     => $method->value,
                'error'      => mb_substr((string) $charge->errorMessage, 0, 300),
            ]);

            throw CheckoutException::make('gateway_error', 502);
        }

        $instructions = $transparent ? $gateway->paymentInstructions($method->value, (string) $charge->externalPaymentId, $charge->rawResponse ?? []) : null;

        [, , , $stale] = $this->attachCharge($subscription, $invoice, $gateway->code(), $charge, $transparent ? $method : null, $attempt, $key, $correlation, $instructions);

        $this->cancelCharges($gateway, $invoice, $stale);

        if ($transparent && $instructions === null) {
            throw CheckoutException::make('charge_pending', 409);
        }

        return $instructions;
    }

    /**
     * Liga à fatura a cobrança que a recorrência do gateway já emitiu
     * (GET da recorrência): a do vencimento da fatura, ou a 1ª pendente.
     */
    private function linkRecurrenceCharge(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway): void
    {
        if (! $gateway instanceof QueriesGatewayRecurrences) {
            return;
        }

        try {
            $recurrence = $gateway->fetchRecurrence((string) $subscription->gateway_subscription_id);
        } catch (GatewayIntegrationException) {
            return;
        }

        $due    = $invoice->period_start?->toDateString() ?? $invoice->due_at?->toDateString();
        $charge = collect($recurrence?->charges ?? [])
            ->filter(fn ($c) => $c->status !== GatewayRecurrenceChargeDTO::PAID)
            ->sortBy(fn ($c) => $c->dueDate === $due ? 0 : 1)
            ->first();

        if ($charge === null || Invoice::query()->where('gateway_code', $gateway->code())->where('external_invoice_id', $charge->id)->exists()) {
            return;
        }

        Invoice::query()->whereKey($invoice->id)->whereNull('external_invoice_id')->update(array_filter([
            'external_invoice_id' => $charge->id,
            'gateway_code'        => $gateway->code(),
            'payment_url'         => $charge->paymentUrl,
            'status'              => $invoice->status === InvoiceStatus::Draft ? InvoiceStatus::Pending->value : null,
        ]));
    }

    /**
     * Instruções guardadas nesta forma, de uma cobrança da fatura que ainda
     * vale (a vigente ou a da outra forma mantida ao alternar).
     */
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

    private function storeInstructions(Invoice $invoice, CheckoutMethod $method, PaymentInstructionsDTO $instructions): void
    {
        $all                 = (array) ($invoice->payment_instructions ?? []);
        $all[$method->value] = [...$instructions->toArray(), 'charge_id' => $invoice->external_invoice_id];

        $invoice->forceFill(['payment_instructions' => $all])->save();
    }

    /** Pix vencido não serve (o QR não paga mais). */
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

    // ── Cartão ───────────────────────────────────────────────────────────────

    /**
     * Grava o resultado do cartão numa fatura em aberto: aprovado → pago na
     * hora (confirmPayment, que avisa a tela) e as outras cobranças da fatura
     * são canceladas; pendente (3DS, análise) → cobrança vigente aguardando o
     * webhook, sem trocar o cartão da renovação antes da confirmação;
     * recusado → só a tentativa, a fatura segue com a cobrança anterior.
     *
     * @return array<string, mixed>
     */
    private function applyCardCharge(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway, CreateChargeResultDTO $charge, int $attempt, string $key, string $correlation, int $installments): array
    {
        $status = PaymentStatus::fromGatewayStatus($charge->status);

        if (! $charge->success) {
            $this->recordFailedAttempt($subscription, $invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, $charge->errorCode);

            throw CheckoutException::make('gateway_error', 502);
        }

        if ($status->isUnusable()) {
            $this->recordFailedAttempt($subscription, $invoice, $gateway->code(), $attempt, $key, $correlation, (string) $charge->errorMessage, 'declined', $charge);

            throw $this->declined($charge->errorMessage);
        }

        [, $replaced, $invoice, $stale] = $this->attachCharge($subscription, $invoice, $gateway->code(), $charge, CheckoutMethod::Card, $attempt, $key, $correlation, null, $installments);

        $this->cancelCharges($gateway, $invoice, $stale);
        $this->cycles->stopRecurrences($replaced, $correlation);

        $paid = $invoice->status === InvoiceStatus::Paid;

        return [
            'invoice'     => $this->invoiceRow($invoice, ! $paid),
            'mode'        => 'transparent',
            'method'      => CheckoutMethod::Card->value,
            'status'      => $paid ? 'paid' : ($charge->nextAction ? 'requires_action' : 'pending'),
            'next_action' => $charge->nextAction,
            'card'        => $charge->savedCard ? ['brand' => $charge->savedCard->brand, 'last4' => $charge->savedCard->last4] : null,
        ];
    }

    /**
     * A nova cobrança passa a ser a vigente da fatura (tentativa, Payment,
     * ids e forma). Quais das anteriores deixam de valer (devolvidas para
     * cancelar no gateway depois do commit):
     *  - paga na hora: todas as outras;
     *  - Pix/boleto: a anterior da mesma forma (ou sem instruções guardadas);
     *    a da outra forma segue valendo;
     *  - cartão pendente (3DS/análise): nenhuma, até a confirmação.
     *
     * $method null = cobrança padrão do gateway (link).
     *
     * @return array{0: ?string, 1: Collection<int, Subscription>, 2: Invoice, 3: list<string>}
     */
    private function attachCharge(
        Subscription $subscription,
        Invoice $invoice,
        string $gatewayCode,
        CreateChargeResultDTO $charge,
        ?CheckoutMethod $method,
        int $attempt,
        string $key,
        string $correlation,
        ?PaymentInstructionsDTO $instructions = null,
        int $installments = 1,
    ): array {
        return DB::transaction(function () use ($subscription, $invoice, $gatewayCode, $charge, $method, $attempt, $key, $correlation, $instructions, $installments): array {
            Entity::query()->whereKey($subscription->entity_id)->lockForUpdate()->first();
            $locked    = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $invoice   = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $gatewayId = Gateway::query()->where('code', $gatewayCode)->value('id');
            $status    = PaymentStatus::fromGatewayStatus($charge->status);
            $previous  = $invoice->external_invoice_id;
            $newId     = (string) $charge->externalPaymentId;
            $isCard    = $method?->isCard() ?? false;

            $attemptRow = PaymentAttempt::query()->create([
                'entity_id'        => $locked->entity_id,
                'subscription_id'  => $locked->id,
                'invoice_id'       => $invoice->id,
                'gateway_id'       => $gatewayId,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attempt,
                'status'           => PaymentAttemptStatus::Succeeded->value,
                'trigger'          => 'checkout',
                'request_payload'  => ['invoice_id' => $invoice->id, 'method' => $method?->value, 'installments' => $installments, 'charge_idempotency_key' => $key],
                'response_payload' => $charge->rawResponse,
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $key,
                'correlation_id'   => $correlation,
            ]);

            $payment = Payment::query()
                ->where('gateway_code', $gatewayCode)
                ->where('external_payment_id', $charge->externalPaymentId)
                ->first() ?? Payment::query()->create([
                    'entity_id'           => $locked->entity_id,
                    'invoice_id'          => $invoice->id,
                    'subscription_id'     => $locked->id,
                    'gateway_id'          => $gatewayId,
                    'gateway_code'        => $gatewayCode,
                    'external_payment_id' => $charge->externalPaymentId,
                    'status'              => PaymentStatus::Pending->value,
                    'amount'              => $charge->amount ?? (float) $invoice->amount,
                    'currency'            => $invoice->currency,
                    'payment_method'      => $method?->value,
                    'raw_gateway_payload' => $charge->rawResponse,
                    'idempotency_key'     => 'payment-' . $key,
                    'correlation_id'      => $correlation,
                    'metadata'            => array_filter(['source' => 'checkout', 'installments' => $isCard ? $installments : null]),
                ]);

            $attemptRow->update(['payment_id' => $payment->id]);

            // O webhook DESTA mesma cobrança confirmou antes de o gateway
            // responder (corrida do cartão aprovado): o pagamento já foi
            // aplicado — nada a estornar. Só o cartão aprovado passa a ser o
            // da renovação (o webhook não o conhece).
            if ($invoice->status === InvoiceStatus::Paid && $invoice->isSettledByCharge($newId, $payment)) {
                if ($isCard && $charge->savedCard !== null && $status === PaymentStatus::Paid) {
                    $payload         = $locked->gateway_payload ?? [];
                    $payload['card'] = $this->cardReferences($charge);

                    $locked->update([...$this->cardColumns($charge->savedCard, $installments), 'gateway_payload' => $payload]);
                }

                $this->billingLog->log(
                    level: 'info',
                    message: 'Checkout: pagamento já confirmado pelo webhook desta mesma cobrança.',
                    context: ['external_charge_id' => $newId, 'method' => $method?->value],
                    entityId: (string) $locked->entity_id,
                    subscription: $locked,
                    invoice: $invoice,
                    payment: $payment,
                    gatewayCode: $gatewayCode,
                    correlationId: $correlation,
                );

                return [null, collect(), $invoice, []];
            }

            // A fatura foi paga (webhook de outra cobrança) enquanto o
            // gateway respondia: registra a nova sem trocar nada — e ela deixa
            // de valer (pendente: cancelar; paga: estornar).
            if ($invoice->status === InvoiceStatus::Paid) {
                $this->billingLog->log(
                    level: $status === PaymentStatus::Paid ? 'critical' : 'warning',
                    message: $status === PaymentStatus::Paid
                        ? 'Checkout: fatura já paga recebeu outro pagamento — estornar no gateway.'
                        : 'Checkout: cobrança emitida para fatura já paga — cancelada no gateway quando possível.',
                    context: ['external_charge_id' => $charge->externalPaymentId, 'method' => $method?->value],
                    entityId: (string) $locked->entity_id,
                    subscription: $locked,
                    invoice: $invoice,
                    payment: $payment,
                    gatewayCode: $gatewayCode,
                    correlationId: $correlation,
                );

                return [null, collect(), $invoice, $status === PaymentStatus::Paid ? [] : [$newId]];
            }

            $entries   = (array) ($invoice->payment_instructions ?? []);
            $live      = $invoice->liveChargeIds($newId);
            $metadata  = (array) ($invoice->metadata ?? []);
            $keepAlive = [];

            if ($status !== PaymentStatus::Paid) {
                if ($isCard) {
                    // Cartão aguardando (3DS/análise): o Pix/boleto segue valendo.
                    $keepAlive = $live;
                } else {
                    // Mantém a cobrança de OUTRA forma que tem instruções guardadas.
                    foreach ($entries as $entryMethod => $entry) {
                        $id = (string) data_get($entry, 'charge_id', '');

                        if ($id !== '' && $entryMethod !== $method?->value && in_array($id, $live, true)) {
                            $keepAlive[] = $id;
                        }
                    }
                }
            }

            $stale = array_values(array_diff($live, $keepAlive));

            // Instruções: as que seguem valendo + as da nova cobrança.
            $entries = array_filter($entries, fn ($entry) => in_array((string) data_get($entry, 'charge_id', ''), $keepAlive, true));

            if ($instructions !== null && $method !== null) {
                $entries[$method->value] = [...$instructions->toArray(), 'charge_id' => $newId];
            }

            if (filled($previous) && $previous !== $newId) {
                $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $previous]));
            }

            if ($stale !== []) {
                $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$stale]));

                Payment::query()
                    ->where('gateway_code', $gatewayCode)
                    ->whereIn('external_payment_id', $stale)
                    ->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Cancelled->value]);
            }

            $metadata['checkout'] = [...(array) ($metadata['checkout'] ?? []), 'reissued' => true, 'method' => $method?->value, 'at' => now()->toIso8601String()];
            unset($metadata['checkout']['pending_card']);

            // Cartão aguardando confirmação: só vira o da renovação quando
            // pago (SubscriptionCycleService::applyPendingCard).
            if ($isCard && $charge->savedCard !== null && $status !== PaymentStatus::Paid) {
                $metadata['checkout']['pending_card'] = [
                    'id'           => $charge->savedCard->id,
                    'brand'        => $charge->savedCard->brand,
                    'last4'        => $charge->savedCard->last4,
                    'installments' => $installments,
                    'references'   => $this->cardReferences($charge),
                ];
            }

            $invoice->forceFill([
                'gateway_id'           => $gatewayId ?? $invoice->gateway_id,
                'gateway_code'         => $gatewayCode,
                'external_invoice_id'  => $charge->externalPaymentId,
                'payment_method'       => $method?->value,
                'payment_instructions' => $entries !== [] ? $entries : null,
                'payment_url'          => $charge->paymentUrl ?: ($instructions?->paymentUrl ?: $invoice->payment_url),
                'raw_gateway_payload'  => $charge->rawResponse,
                'status'               => InvoiceStatus::Pending->value,
                'metadata'             => $metadata,
            ])->save();

            if ($isCard && $charge->savedCard !== null && $status === PaymentStatus::Paid) {
                $payload         = $locked->gateway_payload ?? [];
                $payload['card'] = $this->cardReferences($charge);

                $locked->update([...$this->cardColumns($charge->savedCard, $installments), 'gateway_payload' => $payload]);
            }

            $replaced = collect();

            if ($status === PaymentStatus::Paid) {
                $replaced = $this->cycles->confirmPayment(
                    subscription: $locked,
                    invoice: $invoice,
                    payment: $payment,
                    dueDate: $invoice->period_start ?? $invoice->due_at ?? now(),
                    correlationId: $correlation,
                    source: 'checkout',
                );
            }

            $this->billingLog->log(
                level: 'info',
                message: 'Checkout: cobrança da fatura emitida pela tela de pagamento.',
                context: [
                    'method'          => $method?->value,
                    'payment_status'  => $status->value,
                    'previous_charge' => filled($previous) && $previous !== $newId ? $previous : null,
                    'kept_alive'      => $keepAlive,
                    'cancelled'       => $stale,
                    'installments'    => $isCard ? $installments : null,
                ],
                entityId: (string) $locked->entity_id,
                subscription: $locked,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $gatewayCode,
                correlationId: $correlation,
            );

            return [filled($previous) && $previous !== $newId ? (string) $previous : null, $replaced, $invoice->refresh(), $stale];
        });
    }

    /**
     * Referências da 1ª cobrança no cartão que a renovação reenvia ao gateway
     * (Pagar.me payment_origin.charge_id; Mercado Pago reference.id e
     * network_transaction_id do Automatic Payments).
     *
     * @return array<string, mixed>
     */
    private function cardReferences(CreateChargeResultDTO $charge): array
    {
        return array_filter([
            'origin_payment_id'      => $charge->externalPaymentId,
            'network_transaction_id' => data_get($charge->rawResponse, 'easyeye_card.network_transaction_id'),
            'payment_method_id'      => data_get($charge->rawResponse, 'easyeye_card.payment_method_id'),
            'sequence'               => 1,
        ], fn ($value) => $value !== null);
    }

    /**
     * Cancela no gateway as cobranças que deixaram de valer (melhor esforço;
     * sem API, o time é avisado — se forem pagas, o pagamento entra na fatura
     * ou vira crédito a estornar).
     *
     * @param list<string> $externalIds
     */
    private function cancelCharges(PaymentGatewayInterface $gateway, Invoice $invoice, array $externalIds): void
    {
        foreach (array_unique(array_filter($externalIds)) as $id) {
            try {
                $cancelled = $gateway->cancelCharge($id);
            } catch (Throwable) {
                $cancelled = false;
            }

            if (! $cancelled) {
                $this->billingLog->log(
                    level: 'warning',
                    message: 'Checkout: cobrança que deixou de valer não pôde ser cancelada no gateway (expira sozinha ou cancelar manualmente). Se for paga, entra como pagamento da fatura.',
                    context: ['external_charge_id' => $id],
                    entityId: (string) $invoice->entity_id,
                    invoice: $invoice,
                    gatewayCode: $gateway->code(),
                );
            }
        }
    }

    // ── Regras ───────────────────────────────────────────────────────────────

    /**
     * Fatura da clínica (de outra = 404) que ainda pode ser paga: da
     * assinatura vigente cobrada pelo gateway, em aberto.
     *
     * @return array{0: Subscription, 1: Invoice}
     */
    private function payableInvoiceOf(Entity $entity, string $invoiceId): array
    {
        $this->assertClient($entity);

        if (! Str::isUuid($invoiceId)) {
            abort(404);
        }

        $invoice = Invoice::query()->where('entity_id', $entity->id)->whereKey($invoiceId)->first();

        abort_if($invoice === null, 404);

        $subscription = $invoice->subscription_id
            ? Subscription::query()->with(['entity', 'plan'])->where('entity_id', $entity->id)->find($invoice->subscription_id)
            : null;

        if ($subscription === null || ! $this->isBillable($subscription) || ! $this->isPayable($subscription, $invoice)) {
            throw CheckoutException::make('invoice_not_payable', 409);
        }

        return [$subscription, $invoice];
    }

    /** Fatura de pacote de créditos de IA da clínica (o pagamento é do AiCreditPackCheckoutService). */
    private function aiPackInvoice(Entity $entity, string $invoiceId): ?Invoice
    {
        $this->assertClient($entity);

        return $this->aiPacks->packInvoiceOf($entity, $invoiceId);
    }

    /**
     * A fatura pode ser paga agora em Minha assinatura pela clínica: é da
     * assinatura vigente e cobrável dela e segue em aberto (mesma regra da
     * lista "em aberto" do resumo). Usado pelo manager ("Enviar cobrança à
     * clínica").
     */
    public function canPayInPanel(Subscription $subscription, Invoice $invoice): bool
    {
        if ($invoice->subscription_id !== $subscription->id || ! $this->isBillable($subscription)) {
            return false;
        }

        $entity = $subscription->entity ?? Entity::query()->find($subscription->entity_id);

        return $entity !== null
            && $this->currentSubscription($entity)?->id === $subscription->id
            && $this->isPayable($subscription, $invoice);
    }

    private function isBillable(Subscription $subscription): bool
    {
        return $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && blank($subscription->cancelled_reason)
            && filled($subscription->gateway)
            && ! $subscription->needsBillingReconciliation();
    }

    private function isPayable(Subscription $subscription, Invoice $invoice): bool
    {
        if ($invoice->billing_reason === SubscriptionCycleService::BILLING_REASON_UNAPPLIED) {
            return false;
        }

        // Diferença do upgrade: só enquanto a assinatura segue no plano,
        // ciclo e período em que o valor foi calculado.
        if ($invoice->isPlanChange() && ! PlanChangeService::isPlanChangeValid($subscription, $invoice)) {
            return false;
        }

        return in_array($invoice->status, self::PAYABLE_STATUSES, true)
            || ($invoice->status === InvoiceStatus::Cancelled && $invoice->id === $subscription->current_invoice_id);
    }

    /** @return Collection<int, Invoice> */
    private function payableInvoices(Subscription $subscription): Collection
    {
        return $subscription->invoices()
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Invoice $invoice) => $this->isPayable($subscription, $invoice))
            ->values();
    }

    private function billableSubscription(Entity $entity): Subscription
    {
        $this->assertClient($entity);

        $subscription = Subscription::query()
            ->forEntity((string) $entity->id)
            ->withoutFailedActivations()
            ->with(['entity', 'plan'])
            ->currentFirst()
            ->first();

        if ($subscription === null || ! $this->isBillable($subscription)) {
            throw CheckoutException::make('nothing_to_pay', 409);
        }

        return $subscription;
    }

    /** A assinatura vigente da clínica (a mais recente que não falhou na ativação). */
    private function currentSubscription(Entity $entity): ?Subscription
    {
        return Subscription::query()
            ->forEntity((string) $entity->id)
            ->withoutFailedActivations()
            ->with(['entity', 'plan'])
            ->currentFirst()
            ->first();
    }

    private function isSameTerms(Subscription $subscription, Plan $plan, BillingCycle $cycle): bool
    {
        return $subscription->plan_id === $plan->id && $subscription->effectiveCycle() === $cycle;
    }

    /** Ciclo das parcelas da fatura: o novo ciclo no upgrade, senão o contratado. */
    private function invoiceCycle(Subscription $subscription, Invoice $invoice): ?BillingCycle
    {
        $changeCycle = $invoice->isPlanChange() ? BillingCycle::tryFrom((string) data_get($invoice->metadata, 'plan_change.billing_cycle')) : null;

        return $changeCycle ?? $subscription->effectiveCycle();
    }

    /** Contratação pelo checkout ainda sem o 1º pagamento, do mesmo plano e ciclo. */
    private function pendingContract(?Subscription $current, Plan $plan, BillingCycle $cycle): ?Subscription
    {
        return $current
            && $current->isAwaitingFirstPayment()
            && $this->isBillable($current)
            && $current->plan_id === $plan->id
            && $current->billing_cycle === $cycle
            ? $current
            : null;
    }

    private function assertNotAlreadyActive(Entity $entity, Plan $plan, BillingCycle $cycle): void
    {
        $active = Subscription::query()
            ->forEntity((string) $entity->id)
            ->withoutFailedActivations()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('billing_mode', SubscriptionBillingMode::Gateway->value)
            ->where('plan_id', $plan->id)
            ->where('billing_cycle', $cycle->value)
            ->exists();

        if ($active) {
            throw CheckoutException::make('already_active', 409);
        }
    }

    private function assertClient(Entity $entity): void
    {
        if (! $entity->is_client) {
            throw CheckoutException::make('not_client', 404);
        }
    }

    private function priceOrFail(Plan $plan, BillingCycle $cycle): float
    {
        $price = $plan->active && $plan->isSellable() ? $plan->priceFor($cycle) : null;

        if ($price === null || $price <= 0) {
            throw CheckoutException::make('method_unavailable');
        }

        return (float) $price;
    }

    private function validInstallments(int $requested, PaymentGatewayInterface $gateway, float $amount, ?BillingCycle $cycle): int
    {
        $allowed = collect($this->installmentOptions($amount, $cycle, $gateway->cardCheckoutConfig()))->pluck('count');

        if (! $allowed->contains(max(1, $requested))) {
            throw CheckoutException::make('installments_invalid');
        }

        return max(1, $requested);
    }

    /**
     * Parcelas sem juros (o EasyEye absorve): só nos ciclos de
     * billing.checkout.installment_cycles, até o menor entre o configurado
     * (manager/config), o teto do gateway e os meses do ciclo, com parcela
     * mínima de billing.checkout.min_installment_amount.
     *
     * @return list<array{count: int, amount: float, total: float, interest_free: bool}>
     */
    public function installmentOptions(float $amount, ?BillingCycle $cycle, ?CardCheckoutConfigDTO $card): array
    {
        $max = 1;

        if ($card !== null && $cycle !== null && in_array($cycle->value, (array) config('billing.checkout.installment_cycles', ['yearly']), true)) {
            $max = min(self::maxInstallments(), $card->maxInstallments, max(1, $cycle->months()));
        }

        $minimum = (float) config('billing.checkout.min_installment_amount', 5);
        $options = [];

        for ($count = 1; $count <= $max; $count++) {
            if ($count > 1 && $amount / $count < $minimum) {
                break;
            }

            $options[] = ['count' => $count, 'amount' => round($amount / $count, 2), 'total' => round($amount, 2), 'interest_free' => true];
        }

        return $options;
    }

    /** Teto de parcelas: manager (system_settings.checkout_max_installments) ou config. */
    public static function maxInstallments(): int
    {
        $value = SubscriptionSetting::getValue('checkout_max_installments', config('billing.checkout.max_installments', 12));

        return max(1, min(12, (int) $value));
    }

    // ── Montagem das respostas ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function gatewayOptions(PaymentGatewayInterface $gateway, ?Subscription $subscription, ?float $amount = null, ?BillingCycle $cycle = null): array
    {
        // Cartão sem a chave pública do SDK: pelo link do gateway quando ele
        // tem página que aceita cartão; senão a forma não aparece.
        $methods = collect(CheckoutMethod::cases())
            ->reject(fn (CheckoutMethod $method) => $method->isCard() && ! $gateway->supportsTransparent($method->value) && ! $gateway->supportsHostedCardCheckout() && ! $gateway->supportsCardLink())
            ->map(fn (CheckoutMethod $method) => [
                'method' => $method->value,
                'label'  => __("checkout.methods.{$method->value}"),
                'mode'   => self::methodMode($gateway, $method),
            ])->values()->all();

        $cycle ??= $subscription?->effectiveCycle();
        $amount ??= $subscription?->recurringAmount();

        return [
            'gateway' => $gateway->code(),
            'methods' => $methods,
            ...$this->cardPayload($gateway, $subscription, (float) ($amount ?? 0), $cycle),
        ];
    }

    /** @return array<string, mixed> */
    private function cardPayload(PaymentGatewayInterface $gateway, ?Subscription $subscription, float $amount, ?BillingCycle $cycle, ?Invoice $invoice = null): array
    {
        $config = $gateway->supportsTransparent(CheckoutMethod::Card->value) ? $gateway->cardCheckoutConfig() : null;

        if ($config === null) {
            return [
                'mode'        => self::methodMode($gateway, CheckoutMethod::Card),
                'method'      => CheckoutMethod::Card->value,
                'card'        => null,
                'payment_url' => $invoice?->payment_url,
            ];
        }

        return [
            'mode'   => 'transparent',
            'method' => CheckoutMethod::Card->value,
            'card'   => [
                ...$config->toArray(),
                'installments' => $this->installmentOptions($amount, $cycle, $config),
                'saved_card'   => $subscription?->hasSavedCard() ? ['brand' => $subscription->card_brand, 'last4' => $subscription->card_last4] : null,
                // Renovação no cartão salvo é à vista neste gateway (ver
                // RenewSubscriptionJob): a tela avisa ao parcelar.
                'renewal_in_full' => ! $gateway->supportsRenewalInstallments(),
            ],
        ];
    }

    /**
     * Como a forma é paga neste gateway: transparent (na tela do EasyEye),
     * hosted (cartão no checkout hospedado do gateway, com volta para o
     * EasyEye — Asaas Checkout) ou link (página da cobrança).
     */
    public static function methodMode(PaymentGatewayInterface $gateway, CheckoutMethod $method): string
    {
        return match (true) {
            $gateway->supportsTransparent($method->value)               => 'transparent',
            $method->isCard() && $gateway->supportsHostedCardCheckout() => 'hosted',
            default                                                     => 'link',
        };
    }

    /**
     * Cartão no checkout hospedado (HostedCheckoutService). Leitura (GET):
     * só o checkout já aberto e válido; sem ele, issue_required — o POST
     * abre (fatura de período da assinatura: recorrência no cartão; o resto:
     * cobrança avulsa).
     *
     * @return array<string, mixed>
     */
    private function hostedFor(Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway, bool $issue): array
    {
        $recurrent = $this->hosted->qualifiesForRecurrence($subscription, $invoice, $gateway);

        if (! $issue && ($open = $this->hosted->usableFor($invoice, $gateway, $recurrent)) !== null) {
            return ['invoice' => $this->invoiceRow($invoice, true), ...$this->hosted->response($open, $gateway)];
        }

        if (! $issue) {
            return [
                'invoice'        => $this->invoiceRow($invoice, true),
                'mode'           => 'hosted',
                'method'         => CheckoutMethod::Card->value,
                'checkout_url'   => null,
                'recurrent'      => $recurrent,
                'issue_required' => true,
            ];
        }

        $entity = $subscription->entity ?? Entity::query()->findOrFail($subscription->entity_id);

        return [
            'invoice' => $this->invoiceRow($invoice, true),
            ...$this->hosted->open($entity, $invoice, $subscription, $gateway, $this->customerId($gateway, $subscription, $entity), $recurrent),
        ];
    }

    /** @return array<string, mixed> */
    private function linkResponse(Invoice $invoice, CheckoutMethod $method): array
    {
        return [
            'invoice'     => $this->invoiceRow($invoice, true),
            'mode'        => 'link',
            'method'      => $method->value,
            'payment_url' => $invoice->payment_url,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionRow(Subscription $subscription, bool $billable): array
    {
        $subscription->loadMissing('plan');
        $cycle = $subscription->effectiveCycle();

        return [
            'id'                        => (string) $subscription->id,
            'plan'                      => $subscription->plan ? ['id' => (string) $subscription->plan->id, 'name' => $subscription->plan->name] : null,
            'cycle'                     => $cycle?->value,
            'cycle_label'               => $cycle?->label(),
            'amount'                    => $subscription->recurringAmount(),
            'status'                    => $subscription->status?->value,
            'status_label'              => $subscription->isAwaitingFirstPayment() ? __('subscriptions.status_awaiting_first_payment') : $subscription->status?->label(),
            'billing_mode'              => $subscription->billing_mode?->value,
            'is_awaiting_first_payment' => $subscription->isAwaitingFirstPayment(),
            'access_level'              => $subscription->accessLevel()->value,
            'starts_at'                 => $subscription->starts_at?->toIso8601String(),
            'ends_at'                   => $subscription->ends_at?->toIso8601String(),
            'trial_ends_at'             => $subscription->trial_ends_at?->toIso8601String(),
            'next_billing_at'           => $subscription->next_billing_at?->toDateString(),
            'gateway'                   => $subscription->gateway,
            'payment_method'            => $subscription->payment_method,
            // Cartão da renovação: o guardado no gateway (transparente) ou o
            // da assinatura no cartão do Asaas (Asaas Checkout).
            'card' => $subscription->hasSavedCard() || ($subscription->payment_method === 'credit_card' && filled($subscription->card_last4) && filled($subscription->gateway_subscription_id))
                ? ['brand' => $subscription->card_brand, 'last4' => $subscription->card_last4]
                : null,
            'card_installments' => $subscription->card_installments,
            // A troca de plano refez a recorrência no cartão sem o cartão (a
            // API não recria no cartão sem os dados dele): pagar a próxima
            // fatura no cartão volta a recorrência para o cartão.
            'card_reregister_required' => $billable && is_array(data_get($subscription->gateway_payload, 'card_reregister_required')),
            'can_pay'                  => $billable,
            'can_change_card'          => $billable && $this->resolveGateway((string) $subscription->gateway)->supportsCardReplacement(),
            'renewal_in_full'          => $subscription->hasSavedCard() && (int) $subscription->card_installments > 1 && filled($subscription->gateway)
                && $this->registry->has((string) $subscription->gateway)
                && ! $this->registry->get((string) $subscription->gateway)->supportsRenewalInstallments(),
            'scheduled_change' => ($change = $subscription->scheduledChange()) !== null ? [
                'plan'         => ['id' => (string) $change['plan_id'], 'name' => $change['plan_name'] ?? null],
                'cycle'        => $change['billing_cycle'] ?? null,
                'cycle_label'  => BillingCycle::tryFrom((string) ($change['billing_cycle'] ?? ''))?->label(),
                'amount'       => isset($change['amount']) ? (float) $change['amount'] : null,
                'effective_at' => $change['effective_at'],
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $invoice, bool $payable): array
    {
        return [
            'id'             => (string) $invoice->id,
            'reference'      => $invoice->reference,
            'amount'         => (float) $invoice->amount,
            'currency'       => $invoice->currency,
            'status'         => $invoice->status?->value,
            'due_date'       => $invoice->due_at?->toDateString(),
            'paid_at'        => $invoice->paid_at?->toIso8601String(),
            'period_start'   => $invoice->period_start?->toDateString(),
            'period_end'     => $invoice->period_end?->toDateString(),
            'payment_method' => $invoice->payment_method,
            'payment_url'    => $payable ? $invoice->payment_url : null,
            'can_pay'        => $payable,
            // Pago no checkout hospedado (CHECKOUT_PAID), aguardando a confirmação da cobrança.
            'awaiting_confirmation' => $payable && $this->hosted->awaitingConfirmation($invoice),
            'kind'                  => $invoice->isPlanChange() ? 'plan_change' : 'period',
            'plan_change'           => $invoice->isPlanChange() ? [
                'plan'  => ['id' => (string) data_get($invoice->metadata, 'plan_change.plan_id'), 'name' => data_get($invoice->metadata, 'plan_change.plan_name')],
                'cycle' => data_get($invoice->metadata, 'plan_change.billing_cycle'),
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sellablePlans(): array
    {
        return Plan::active()
            ->with('prices')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Plan $plan) => $plan->isSellable())
            ->map(fn (Plan $plan) => [
                'id'            => (string) $plan->id,
                'name'          => $plan->name,
                'description'   => $plan->description,
                'is_featured'   => (bool) $plan->is_featured,
                'default_cycle' => $plan->defaultCycle()?->value,
                'prices'        => PlanPricing::cycles($plan),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function checkoutSettings(): array
    {
        return [
            'max_installments'   => self::maxInstallments(),
            'installment_cycles' => array_values((array) config('billing.checkout.installment_cycles', ['yearly'])),
        ];
    }

    /** @return array{channel: string, event: string} */
    private function realtime(Entity $entity): array
    {
        return ['channel' => InvoicePaid::channelName((string) $entity->id), 'event' => '.invoice.paid'];
    }

    // ── Infra ────────────────────────────────────────────────────────────────

    /**
     * Gateway da nova contratação: o da clínica/plano ou o padrão do manager;
     * sem nenhum com credencial no banco, o de billing.default_gateway.
     */
    private function newPurchaseGateway(Entity $entity, Plan $plan): PaymentGatewayInterface
    {
        try {
            return $this->resolver->resolveForNewPurchase($entity, $plan);
        } catch (GatewayResolutionException) {
            return $this->resolveGateway((string) config('billing.default_gateway'));
        }
    }

    private function gatewayFor(Subscription $subscription): PaymentGatewayInterface
    {
        return $this->resolveGateway((string) $subscription->gateway);
    }

    private function resolveGateway(string $code): PaymentGatewayInterface
    {
        if ($code === '' || ! $this->registry->has($code)) {
            throw CheckoutException::make('method_unavailable');
        }

        return $this->registry->get($code);
    }

    private function customerId(PaymentGatewayInterface $gateway, Subscription $subscription, Entity $entity): string
    {
        if (filled($subscription->gateway_customer_id)) {
            return (string) $subscription->gateway_customer_id;
        }

        $customerId = $gateway->upsertCustomer(BillingCustomer::customer($entity));
        Subscription::query()->whereKey($subscription->id)->update(['gateway_customer_id' => $customerId]);
        $subscription->gateway_customer_id = $customerId;

        return $customerId;
    }

    private function nextAttemptNumber(Invoice $invoice): int
    {
        return (int) PaymentAttempt::query()->where('invoice_id', $invoice->id)->max('attempt_number') + 1;
    }

    /** @return array<string, mixed> */
    private function cardColumns(SavedCardDTO $card, int $installments): array
    {
        return [
            'payment_method'    => CheckoutMethod::Card->value,
            'gateway_card_id'   => $card->id,
            'card_brand'        => $card->brand,
            'card_last4'        => $card->last4,
            'card_installments' => max(1, $installments),
        ];
    }

    private function declined(?string $reason): CheckoutException
    {
        $reason = trim((string) $reason);

        // Mensagem técnica do gateway (HTTP …) não vai para a tela.
        return $reason !== '' && ! str_starts_with($reason, 'HTTP ') && ! str_starts_with($reason, '[') && mb_strlen($reason) <= 200
            ? CheckoutException::make('card_declined', 422, ['reason' => $reason])
            : CheckoutException::make('card_declined_generic');
    }

    private function recordFailedAttempt(
        Subscription $subscription,
        Invoice $invoice,
        string $gatewayCode,
        int $attempt,
        string $key,
        string $correlation,
        string $error,
        ?string $errorCode,
        ?CreateChargeResultDTO $charge = null,
    ): void {
        DB::transaction(function () use ($subscription, $invoice, $gatewayCode, $attempt, $key, $correlation, $error, $errorCode, $charge): void {
            PaymentAttempt::query()->create([
                'entity_id'        => $subscription->entity_id,
                'subscription_id'  => $subscription->id,
                'invoice_id'       => $invoice->id,
                'gateway_code'     => $gatewayCode,
                'attempt_number'   => $attempt,
                'status'           => PaymentAttemptStatus::Failed->value,
                'trigger'          => 'checkout',
                'request_payload'  => ['invoice_id' => $invoice->id, 'charge_idempotency_key' => $key],
                'response_payload' => $charge?->rawResponse,
                'error_code'       => $errorCode !== null ? mb_substr($errorCode, 0, 80) : null,
                'error_message'    => mb_substr($error, 0, 2000),
                'started_at'       => now(),
                'finished_at'      => now(),
                'idempotency_key'  => $key,
                'correlation_id'   => $correlation,
            ]);

            // Recusa no cartão com id no gateway: eventos dela não mexem na fatura.
            if (filled($charge?->externalPaymentId) && $charge->externalPaymentId !== $invoice->external_invoice_id) {
                $locked   = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $metadata = (array) ($locked->metadata ?? []);

                $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $charge->externalPaymentId]));
                $locked->forceFill(['metadata' => $metadata])->save();
            }
        });
    }

    /**
     * Uma operação de pagamento por clínica de cada vez (trava) e, com a
     * chave do front, o mesmo resultado para o mesmo pedido repetido. O
     * resultado guardado é relido DENTRO da trava (dois pedidos iguais
     * simultâneos: o segundo espera e devolve o do primeiro). Chamadas
     * internas com a trava já tomada (contratar → pagar a fatura) não
     * travam de novo.
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
            throw CheckoutException::make('busy', 409);
        }
    }
}

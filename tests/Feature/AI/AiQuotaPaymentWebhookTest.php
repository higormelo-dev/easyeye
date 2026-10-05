<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet};
use App\Domains\AI\Services\{AiCreditWalletService, AiQuotaService};
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\{BillingCycle, FeatureKey, SubscriptionAccessLevel, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, PlanPrice, Subscription};
use App\Services\Billing\BillingSubscriptionOrchestrator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\Http;

/*
 * Franquia de IA pelo fluxo real de pagamento: webhook do Asaas na rota real
 * (/api/billing/webhooks/asaas) → ProcessWebhookEventService →
 * SubscriptionCycleService::confirmPayment → SubscriptionObserver. A janela
 * concedida é a da âncora (dia da ativação paga), não a do pagamento; o
 * consumo da janela é preservado; webhook repetido e pagamento de outro
 * ciclo dentro da mesma janela não concedem de novo.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['billing.gateways.asaas.webhook_secret' => 'whsec_asaas_teste']);
    Http::preventStrayRequests();
    aqpFakeAsaas();

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 40)->for($this->plan)->create();

    Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();

    // Contratação mensal pelo Asaas: 1º vencimento em 08/10.
    $this->subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Monthly, 'asaas');

    $this->wallet = app(AiCreditWalletService::class);
});

afterEach(fn () => Carbon::setTestNow());

function aqpFakeAsaas(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $request->method() === 'GET'
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => 0, 'data' => []])
                : Http::response(['object' => 'customer', 'id' => 'cus_000005219613']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $request->method() === 'POST') {
            return Http::response([
                'object'            => 'subscription',
                'id'                => 'sub_' . substr(md5((string) $request['externalReference']), 0, 12),
                'customer'          => 'cus_000005219613',
                'billingType'       => 'BOLETO',
                'cycle'             => $request['cycle'],
                'value'             => $request['value'],
                'nextDueDate'       => $request['nextDueDate'],
                'status'            => 'ACTIVE',
                'externalReference' => $request['externalReference'],
            ]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

/** Evento de cobrança no formato do Asaas (parcela da assinatura). */
function aqpEvent(string $event, string $paymentId, string $dueDate, array $payment = [], ?string $eventId = null): array
{
    $subscription = test()->subscription;
    $suffix       = Str::after($paymentId, 'pay_');

    return [
        'id'          => $eventId ?? 'evt_' . Str::random(32),
        'event'       => $event,
        'dateCreated' => now()->format('Y-m-d H:i:s'),
        'payment'     => array_merge([
            'object'            => 'payment',
            'id'                => $paymentId,
            'dateCreated'       => now()->toDateString(),
            'customer'          => 'cus_000005219613',
            'subscription'      => $subscription->gateway_subscription_id,
            'value'             => 299.9,
            'netValue'          => 297.91,
            'description'       => 'Assinatura Pro',
            'billingType'       => 'BOLETO',
            'status'            => 'PENDING',
            'dueDate'           => $dueDate,
            'originalDueDate'   => $dueDate,
            'paymentDate'       => null,
            'clientPaymentDate' => null,
            'invoiceUrl'        => "https://www.asaas.com/i/{$suffix}",
            'externalReference' => $subscription->id,
            'deleted'           => false,
        ], $payment),
    ];
}

function aqpPost(array $payload): void
{
    test()->postJson('/api/billing/webhooks/asaas', $payload, ['asaas-access-token' => 'whsec_asaas_teste'])->assertOk();
}

/** Pagamento recebido de uma parcela, no dia de hoje. */
function aqpPaid(string $paymentId, string $dueDate, ?string $eventId = null): array
{
    return aqpEvent('PAYMENT_RECEIVED', $paymentId, $dueDate, [
        'status'            => 'RECEIVED',
        'paymentDate'       => now()->toDateString(),
        'clientPaymentDate' => now()->toDateString(),
    ], $eventId);
}

function aqpWallet(): AiCreditWallet
{
    return AiCreditWallet::query()->where('entity_id', test()->clinic->id)->sole();
}

/** @return list<string> início das janelas concedidas, em ordem */
function aqpGrantedWindows(): array
{
    return AiCreditLedgerEntry::query()
        ->where('entity_id', test()->clinic->id)
        ->where('type', AiLedgerEntryType::Grant->value)
        ->orderBy('created_at')
        ->get()
        ->map(fn (AiCreditLedgerEntry $entry) => $entry->metadata['window_start'] ?? null)
        ->all();
}

test('renovação paga pelo webhook concede a janela da âncora; webhook repetido e o ciclo seguinte pago na mesma janela não concedem de novo', function () {
    // 1º pagamento em 07/10: ativa e abre a janela 07/10–07/11.
    Carbon::setTestNow('2026-10-07 14:30:00');
    aqpPost(aqpPaid('pay_080225913252', '2026-10-08'));

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(aqpGrantedWindows())->toBe(['2026-10-07'])
        ->and(aqpWallet()->quota_period_ends_at->toDateString())->toBe('2026-11-07');

    $this->wallet->reserve($this->clinic->id, 25);

    // Renovação (vence 08/11) paga em 08/11, sem o agendador ter rodado na
    // virada de 07/11: o pagamento concede a janela 07/11–07/12 (âncora),
    // não uma janela a partir do dia do pagamento.
    Carbon::setTestNow('2026-11-08 09:00:00');
    $renewal = aqpPaid('pay_196527845301', '2026-11-08', 'evt_renovacao_novembro');
    aqpPost($renewal);

    $wallet = aqpWallet();
    expect($this->subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
        ->and(aqpGrantedWindows())->toBe(['2026-10-07', '2026-11-07'])
        ->and($wallet->monthly_quota)->toBe(40)
        ->and($wallet->monthly_quota_used)->toBe(0)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-12-07');

    $this->wallet->reserve($this->clinic->id, 5);

    // Reenvio idêntico do gateway e o CONFIRMED do mesmo pagamento.
    aqpPost($renewal);
    aqpPost(aqpEvent('PAYMENT_CONFIRMED', 'pay_196527845301', '2026-11-08', ['status' => 'CONFIRMED', 'paymentDate' => '2026-11-08']));

    // Ciclo seguinte (vence 08/12) pago antes, ainda na janela 07/11–07/12:
    // o período estende (observer roda), mas a janela já foi concedida.
    Carbon::setTestNow('2026-11-20 10:00:00');
    aqpPost(aqpPaid('pay_207731190044', '2026-12-08'));

    $wallet = aqpWallet();
    expect($this->subscription->fresh()->ends_at->toDateTimeString())->toBe('2027-01-08 23:59:59')
        ->and(aqpGrantedWindows())->toBe(['2026-10-07', '2026-11-07'])
        ->and($wallet->monthly_quota_used)->toBe(5)
        ->and($wallet->quotaRemaining())->toBe(35)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-12-07');
});

test('regularização do atraso pelo webhook concede a janela atual que o acesso limitado deixou sem franquia, uma vez só', function () {
    // 1º pagamento 4 dias depois do vencimento (boleto pago com atraso): a
    // âncora é o dia do pagamento, 12/10 — janelas 12/10, 12/11, 12/12…
    Carbon::setTestNow('2026-10-12 10:00:00');
    aqpPost(aqpPaid('pay_080225913252', '2026-10-08'));

    expect($this->subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and(aqpGrantedWindows())->toBe(['2026-10-12']);

    $this->wallet->reserve($this->clinic->id, 40);

    // Renovação de 08/11 vencida.
    Carbon::setTestNow('2026-11-09 08:00:00');
    aqpPost(aqpEvent('PAYMENT_OVERDUE', 'pay_196527845301', '2026-11-08', ['status' => 'OVERDUE']));

    // 12/11 (D+4, acesso limitado): a janela 12/11–12/12 começa, mas nem o
    // agendador nem a leitura da carteira concedem.
    Carbon::setTestNow('2026-11-12 00:20:00');
    expect($this->subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);

    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(app(AiQuotaService::class)->snapshot($this->clinic->id)['monthly_quota'])->toBe(0)
        ->and(aqpGrantedWindows())->toBe(['2026-10-12']);

    // Pagou em 13/11: volta a ficar ativa e recebe a janela 12/11–12/12 na hora.
    Carbon::setTestNow('2026-11-13 10:00:00');
    $settlement = aqpPaid('pay_196527845301', '2026-11-08', 'evt_regularizacao_novembro');
    aqpPost($settlement);

    $wallet = aqpWallet();
    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(aqpGrantedWindows())->toBe(['2026-10-12', '2026-11-12'])
        ->and($wallet->monthly_quota)->toBe(40)
        ->and($wallet->monthly_quota_used)->toBe(0)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-12-12');

    $this->wallet->reserve($this->clinic->id, 5);

    // Reenvio do mesmo pagamento, o CONFIRMED dele e o agendador do dia seguinte.
    aqpPost($settlement);
    aqpPost(aqpEvent('PAYMENT_CONFIRMED', 'pay_196527845301', '2026-11-08', ['status' => 'CONFIRMED', 'paymentDate' => '2026-11-13']));
    Carbon::setTestNow('2026-11-14 00:20:00');
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect(aqpGrantedWindows())->toBe(['2026-10-12', '2026-11-12'])
        ->and(aqpWallet()->monthly_quota_used)->toBe(5);
});

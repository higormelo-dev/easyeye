<?php

declare(strict_types=1);

use App\DTOs\Billing\CreateChargeDTO;
use App\Enums\Billing\{DunningStep, InvoiceStatus};
use App\Enums\{BillingCycle, ClientRule};
use App\Models\Billing\{Invoice, SubscriptionDunningStep};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{BillingSubscriptionOrchestrator, DunningService, GatewayRegistry, SubscriptionCycleService, SubscriptionNoticeService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Http, Notification};

/**
 * Renovação local (gateways sem recorrência própria): a cobrança do próximo
 * ciclo sai um dia antes do lembrete da régua (antecedência mínima =
 * reminder_days_before + 1, comparada por dia), com o link de pagamento que
 * o gateway devolve já na emissão — o lembrete do D-5 leva o link. Sem a
 * cobrança emitida, o lembrete espera (até o último dia da antecedência).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Notification::fake();

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Lembrete']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->admin = User::factory()->create();
    createEntityUser($this->clinic, $this->admin, ClientRule::Admin->value, isOwner: true);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);

    // Emissão no Mercado Pago: true = Pix criado (com ticket_url na resposta); false = 500.
    $this->mpUp = true;
    $this->mpId = 1316372291;

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.mercadopago.com/v1/customers')) {
            return Http::response(['id' => '1234567-cbXRL1mI2X'], 201);
        }

        if ($url === 'https://api.mercadopago.com/v1/payments' && $request->method() === 'POST') {
            if (! test()->mpUp) {
                return Http::response(['message' => 'internal_error'], 500);
            }

            $id = test()->mpId++;

            return Http::response([
                'id'                   => $id,
                'status'               => 'pending',
                'transaction_amount'   => $request['transaction_amount'],
                'external_reference'   => $request['external_reference'],
                'date_of_expiration'   => $request['date_of_expiration'],
                'point_of_interaction' => ['transaction_data' => ['ticket_url' => "https://www.mercadopago.com.br/payments/{$id}/ticket"]],
            ], 201);
        }

        // Outros gateways: os stubs de cada teste.
        return str_starts_with($url, 'https://api.mercadopago.com')
            ? Http::response(['message' => 'not_found', 'results' => []], 404)
            : null;
    });
});

afterEach(fn () => Carbon::setTestNow());

/** Cliente pagante em renovação local (Mercado Pago, sem recorrência no gateway). */
function rrlLocalRenewal(string $nextBilling, array $attributes = []): Subscription
{
    return Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => null,
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'last_payment_at'         => CarbonImmutable::parse($nextBilling)->subMonth(),
        'ends_at'                 => $nextBilling,
        'next_billing_at'         => $nextBilling,
        ...$attributes,
    ]);
}

function rrlDunningAt(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DunningService::class)->run();
}

function rrlRenewAt(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(SubscriptionCycleService::class)->dispatchDueRenewals();
}

function rrlReminder(): ?SubscriptionDunningNotification
{
    return Notification::sent(test()->admin, SubscriptionDunningNotification::class, fn ($n) => $n->step === DunningStep::Reminder)->first();
}

it('a contratação grava o link do Pix já na emissão: "Pagar agora" no painel sem esperar o webhook', function () {
    $subscription = app(BillingSubscriptionOrchestrator::class)
        ->activateWithGateway($this->clinic, $this->plan, BillingCycle::Monthly, 'mercadopago');

    $banner = app(SubscriptionNoticeService::class)->banner($subscription, $this->admin);

    expect($subscription->currentInvoice->payment_url)->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket')
        ->and($banner['kind'])->toBe('first_payment_pending')
        ->and($banner['payment_url'])->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket');
});

it('renovação local sai no D-6 (antes do lembrete) com o link, e o lembrete do D-5 leva esse link', function () {
    $subscription = rrlLocalRenewal('2026-10-15 23:59:59');

    // D-7 à 01:00: fora da antecedência.
    expect(rrlRenewAt('2026-10-08 01:00:00'))->toBe(0);

    // D-6 à 01:00: a cobrança do período é emitida, com o link da resposta.
    expect(rrlRenewAt('2026-10-09 01:00:00'))->toBe(1);

    $renewal = Invoice::query()->where('subscription_id', $subscription->id)->sole();

    expect($renewal->period_start->toDateString())->toBe('2026-10-15')
        ->and($renewal->status)->toBe(InvoiceStatus::Pending)
        ->and($renewal->payment_url)->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket');

    // D-5 às 09:00: o lembrete sai com o link da cobrança.
    expect(rrlDunningAt('2026-10-10 09:00:00')[DunningStep::Reminder->value])->toBe(1)
        ->and(rrlReminder()->context['payment_url'])->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket')
        // O e-mail leva para pagar DENTRO do sistema (fatura em Minha assinatura).
        ->and(rrlReminder()->toMail($this->admin)->actionUrl)->toBe(route('panel.my-subscription.index', ['invoice' => rrlReminder()->context['invoice_id']]));
});

it('com a emissão falhando, o lembrete espera a cobrança existir — sem gravar a etapa — e sai uma vez quando ela é emitida', function () {
    $subscription = rrlLocalRenewal('2026-10-15 23:59:59');
    $this->mpUp   = false;

    rrlRenewAt('2026-10-09 01:00:00');
    rrlRenewAt('2026-10-10 01:00:00');

    expect(rrlDunningAt('2026-10-10 09:00:00')[DunningStep::Reminder->value])->toBe(0)
        ->and(SubscriptionDunningStep::query()->where('subscription_id', $subscription->id)->exists())->toBeFalse();

    Notification::assertNothingSent();

    // D-4: o gateway voltou; a cobrança sai à 01:00 e o lembrete às 09:00, com o link.
    $this->mpUp = true;
    rrlRenewAt('2026-10-11 01:00:00');

    expect(rrlDunningAt('2026-10-11 09:00:00')[DunningStep::Reminder->value])->toBe(1)
        ->and(rrlReminder()->context['payment_url'])->toStartWith('https://www.mercadopago.com.br/payments/');

    // Não repete nos dias seguintes.
    expect(rrlDunningAt('2026-10-12 09:00:00')[DunningStep::Reminder->value])->toBe(0);
    Notification::assertSentToTimes($this->admin, SubscriptionDunningNotification::class, 1);
});

it('sem cobrança emitida até o último dia da antecedência, o lembrete sai mesmo assim (D-1), sem link', function () {
    $subscription = rrlLocalRenewal('2026-10-15 23:59:59');
    $this->mpUp   = false;

    expect(rrlDunningAt('2026-10-13 09:00:00')[DunningStep::Reminder->value])->toBe(0)
        ->and(rrlDunningAt('2026-10-14 09:00:00')[DunningStep::Reminder->value])->toBe(1)
        ->and(rrlReminder()->context['payment_url'])->toBeNull()
        ->and(SubscriptionDunningStep::query()->where('subscription_id', $subscription->id)->sole()->due_on->toDateString())->toBe('2026-10-15');

    // Sem link: nada de "Pagar agora" nem promessa de e-mail do gateway — o caminho é o contato.
    $mail = rrlReminder()->toMail($this->admin);

    expect(implode(' ', $mail->introLines))->toContain(__('billing_dunning.reminder.no_link'))
        ->and($mail->actionText)->toBe(__('billing_dunning.contact'))
        ->and($mail->actionUrl)->toBe(route('site.home') . '#contato')
        ->and(__('billing_dunning.reminder.no_link', [], 'pt_BR'))->not->toContain('gateway')
        ->and(__('billing_dunning.reminder.no_link', [], 'en'))->not->toContain('gateway');
});

it('recorrência nativa (o gateway emite a cobrança): o lembrete do D-5 não espera', function () {
    Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
        'billing_cycle'   => BillingCycle::Monthly,
        'amount'          => 299.90,
        'last_payment_at' => '2026-09-15 10:00:00',
        'ends_at'         => '2026-10-15 23:59:59',
        'next_billing_at' => '2026-10-15 23:59:59',
    ]);

    expect(rrlDunningAt('2026-10-10 09:00:00')[DunningStep::Reminder->value])->toBe(1);
});

/** Link de pagamento devolvido pelo createCharge do gateway. */
function rrlCharge(string $gateway): ?string
{
    return app(GatewayRegistry::class)->get($gateway)->createCharge(new CreateChargeDTO(
        entityId: (string) test()->clinic->id,
        invoiceId: 'inv-1',
        subscriptionId: 'sub-1',
        customerId: 'cus-1',
        amount: 299.90,
        currency: 'BRL',
        description: 'Fatura INV-1',
        dueDate: '2026-10-15',
        paymentMethod: 'boleto',
    ))->paymentUrl;
}

describe('createCharge devolve o link de pagamento quando a resposta do gateway o traz', function () {
    it('Asaas: invoiceUrl (página da fatura)', function () {
        Http::fake(['https://api.asaas.com/v3/payments' => Http::response([
            'id'          => 'pay_080225913252',
            'status'      => 'PENDING',
            'value'       => 299.90,
            'invoiceUrl'  => 'https://www.asaas.com/i/080225913252',
            'bankSlipUrl' => 'https://www.asaas.com/b/pdf/080225913252',
        ])]);

        expect(rrlCharge('asaas'))->toBe('https://www.asaas.com/i/080225913252');
    });

    it('Mercado Pago: point_of_interaction.transaction_data.ticket_url', function () {
        expect(rrlCharge('mercadopago'))->toBe('https://www.mercadopago.com.br/payments/1316372291/ticket');
    });

    it('PagBank: PDF do boleto nos links da cobrança (nunca o link da API)', function () {
        Http::fake(['https://api.pagseguro.com/orders' => Http::response([
            'id'      => 'ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328',
            'charges' => [[
                'id'     => 'CHAR_67D0D02B-4F1C-41C1-B47C-D52E0E2B6CA9',
                'status' => 'WAITING',
                'links'  => [
                    ['rel' => 'SELF', 'href' => 'https://api.pagseguro.com/charges/CHAR_67D0D02B', 'media' => 'application/json', 'type' => 'GET'],
                    ['rel' => 'SELF', 'href' => 'https://boleto.pagseguro.com.br/67d0d02b.pdf', 'media' => 'application/pdf', 'type' => 'GET'],
                ],
            ]],
        ])]);

        // O PagBank exige o CPF/CNPJ do comprador (customer.tax_id): sem ele
        // a cobrança nem é enviada.
        $charge = app(GatewayRegistry::class)->get('pagbank')->createCharge(new CreateChargeDTO(
            entityId: (string) test()->clinic->id,
            invoiceId: 'inv-1',
            subscriptionId: 'sub-1',
            customerId: 'cus-1',
            amount: 299.90,
            currency: 'BRL',
            description: 'Fatura INV-1',
            dueDate: '2026-10-15',
            paymentMethod: 'boleto',
            metadata: ['customer_name' => 'Clínica Lembrete', 'email' => 'financeiro@clinica.test', 'document' => '11222333000181'],
        ));

        expect($charge->paymentUrl)->toBe('https://boleto.pagseguro.com.br/67d0d02b.pdf');
    });

    it('Pagar.me: url do boleto na última transação', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response([
            'id'      => 'or_56GXnk6T0eU88qMm',
            'amount'  => 29990,
            'charges' => [[
                'id'               => 'ch_d22356Jf4WuGr8no',
                'status'           => 'pending',
                'last_transaction' => ['url' => 'https://api.pagar.me/core/v5/transactions/tran_1/boleto', 'pdf' => 'https://api.pagar.me/core/v5/transactions/tran_1/pdf'],
            ]],
        ])]);

        expect(rrlCharge('pagarme'))->toBe('https://api.pagar.me/core/v5/transactions/tran_1/boleto');
    });

    it('só aceita link http(s)', function () {
        Http::fake(['https://api.asaas.com/v3/payments' => Http::response([
            'id'         => 'pay_1',
            'status'     => 'PENDING',
            'invoiceUrl' => 'javascript:alert(1)',
        ])]);

        expect(rrlCharge('asaas'))->toBeNull();
    });
});

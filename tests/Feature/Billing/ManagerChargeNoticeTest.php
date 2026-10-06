<?php

declare(strict_types=1);

use App\Enums\Billing\InvoiceStatus;
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Models\Billing\{Invoice, SubscriptionChange};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Notifications\InvoiceChargeNotification;
use App\Services\Billing\InvoiceChargeNoticeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Http, Notification};

/**
 * Rodada 5 — A3: o manager faz o upgrade e ENVIA a cobrança à clínica
 * (e-mail + WhatsApp aos contatos de cobrança) com o link para pagar DENTRO
 * do sistema (/panel/my-subscription?invoice=<id>) — nunca o do gateway.
 * Um envio por fatura a cada 10 min, auditado; fatura paga/cancelada: 409.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00'));
    Http::preventStrayRequests();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Boa Vista']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    // Contatos de cobrança (recebem) e perfis clínicos (não recebem).
    $this->clinicAdmin = User::factory()->create(['phone' => '11988887777']);
    createEntityUser($this->clinic, $this->clinicAdmin, ClientRule::Admin->value);
    $this->financial = User::factory()->create();
    createEntityUser($this->clinic, $this->financial, ClientRule::Financial->value);
    $this->secretary = User::factory()->create();
    createEntityUser($this->clinic, $this->secretary, ClientRule::Secretary->value);

    $this->pro     = r5ChargePlan('Pro', 300.00);
    $this->premium = r5ChargePlan('Premium', 600.00);

    $this->subscription = Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->pro)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => 'cus-1',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'amount'                  => 300.00,
        'last_payment_at'         => '2026-10-01 10:00:00',
        'starts_at'               => '2026-09-01 10:00:00',
        'ends_at'                 => '2026-11-01 23:59:59',
        'next_billing_at'         => '2026-11-01 23:59:59',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function r5ChargePlan(string $name, float $price): Plan
{
    $plan = Plan::factory()->create(['name' => $name, 'billing_cycle' => 'monthly', 'price' => $price, 'active' => true]);
    PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'price' => $price]);

    return $plan->fresh('prices');
}

function r5ManagerAs(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

/** Upgrade pelo manager: devolve a fatura da diferença. */
function r5Upgrade(): Invoice
{
    $response = r5ManagerAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => test()->clinic->id,
        'plan_id'       => test()->premium->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'reason'        => 'Clínica pediu o upgrade por telefone (chamado #77).',
    ])->assertCreated();

    return Invoice::query()->findOrFail($response->json('data.invoice_id'));
}

function r5SendCharge(Invoice $invoice): mixed
{
    return r5ManagerAs()->postJson(route('manager.subscriptions.invoices.send-charge', [
        'subscription' => test()->subscription->id,
        'invoice'      => $invoice->id,
    ]));
}

it('envia a diferença do upgrade por e-mail aos contatos de cobrança com o link do sistema e registra quem enviou', function () {
    Notification::fake();
    $invoice = r5Upgrade();
    $invoice->update(['payment_url' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=gateway-link']);

    r5SendCharge($invoice)
        ->assertOk()
        ->assertJsonPath('data.recipients', 2)
        ->assertJsonPath('data.whatsapp', 0)
        ->assertJsonPath('data.channels', ['mail'])
        ->assertJsonPath('message', trans_choice('manager_subscriptions.charge_notice.sent', 2, ['count' => 2]));

    $link = route('panel.my-subscription.index', ['invoice' => $invoice->id]);

    Notification::assertSentTo([$this->clinicAdmin, $this->financial], InvoiceChargeNotification::class, function (InvoiceChargeNotification $n, array $channels, User $user) use ($link) {
        $mail = $n->toMail($user);
        $text = implode(' ', [...$mail->introLines, ...$mail->outroLines, $mail->subject]);

        return $channels === ['mail']
            && $mail->actionUrl === $link
            && ! str_contains($text, 'mercadopago')
            && str_contains($text, 'Premium')
            && str_contains($text, '16/10/2026') === false // vencimento é o da fatura, não a data do envio
            && str_contains($text, 'R$');
    });
    Notification::assertNotSentTo([$this->secretary, $this->admin], InvoiceChargeNotification::class);

    $audit = SubscriptionChange::query()->where('change_type', InvoiceChargeNoticeService::CHANGE_TYPE)->sole();
    expect($audit->changed_by)->toBe($this->admin->id)
        ->and($audit->metadata['invoice_id'])->toBe($invoice->id)
        ->and($audit->metadata['channels'])->toBe(['mail'])
        ->and($audit->metadata['recipients'])->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['event' => 'manager.subscription.charge_notice', 'auditable_id' => $invoice->id]);

    // Detalhe e faturas mostram a fatura em aberto e o último envio.
    r5ManagerAs()->getJson(route('manager.subscriptions.show', $this->subscription))
        ->assertJsonPath('data.open_invoice.id', $invoice->id)
        ->assertJsonPath('data.open_invoice.plan_change', true)
        ->assertJsonPath('data.open_invoice.last_notice.by', $this->admin->fresh()->name);
    r5ManagerAs()->getJson(route('manager.subscriptions.invoices', $this->subscription))
        ->assertJsonPath('data.0.can_send_charge', true)
        ->assertJsonPath('data.0.last_charge_notice.channels', ['mail']);
});

it('um envio por fatura a cada 10 minutos (429), depois libera de novo', function () {
    Notification::fake();
    $invoice = r5Upgrade();

    r5SendCharge($invoice)->assertOk();
    r5SendCharge($invoice)->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    $this->travel(11)->minutes();
    r5SendCharge($invoice)->assertOk();

    expect(SubscriptionChange::query()->where('change_type', InvoiceChargeNoticeService::CHANGE_TYPE)->count())->toBe(2);
});

it('fatura paga ou cancelada: 409 com a mensagem traduzida; fatura de outra assinatura: 404', function () {
    Notification::fake();
    $invoice = r5Upgrade();

    $invoice->update(['status' => InvoiceStatus::Paid->value, 'paid_at' => now()]);
    r5SendCharge($invoice)->assertStatus(409)
        ->assertJsonPath('code', 'invoice_not_payable')
        ->assertJsonPath('message', __('manager_subscriptions.charge_notice.errors.not_payable'));

    $other = Subscription::factory()->gateway('mercadopago')->create();
    $alien = Invoice::query()->create([
        'entity_id'       => $other->entity_id,
        'subscription_id' => $other->id,
        'plan_id'         => $other->plan_id,
        'reference'       => 'INV-ALHEIA',
        'due_at'          => now()->addDays(3),
        'amount'          => 10,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
    ]);
    r5SendCharge($alien)->assertNotFound();

    Notification::assertNothingSent();
});

it('usuário da clínica não envia cobrança pelo manager (403)', function () {
    Notification::fake();
    $invoice = r5Upgrade();

    $this->actingAs($this->clinicAdmin)->withSession(['selected_entity_id' => $this->clinic->id, 'selected_entity_is_client' => true])
        ->postJson(route('manager.subscriptions.invoices.send-charge', ['subscription' => $this->subscription->id, 'invoice' => $invoice->id]))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('WhatsApp pelo app global do EasyEye (template) para o contato com telefone verificado, com o link do sistema', function () {
    useGupshupDriver();
    WhatsAppSetting::create([
        'entity_id'     => null,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'app_id'        => 'GLOBAL-R5',
    ]);
    $this->clinicAdmin->forceFill(['phone_verified_at' => now()])->save();

    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::response(['messages' => [['id' => 'gs-r5']]])]);

    $invoice = r5Upgrade();

    r5SendCharge($invoice)
        ->assertOk()
        ->assertJsonPath('data.whatsapp', 1)
        ->assertJsonPath('data.channels', ['mail', 'whatsapp']);

    $messages = gupshupSentMessages();
    $template = gupshupTemplateOf($messages[0]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->url())->toBe('https://partner.gupshup.io/partner/app/GLOBAL-R5/v3/message')
        ->and($messages[0]->header('Authorization')[0])->toBe('sk_app_token')
        ->and($messages[0]['to'])->toBe('5511988887777')
        ->and($template['name'])->toBe('easyeye_cobranca_troca_plano')
        ->and($template['body'])->toContain('Premium')
        ->and($template['buttons'][0])->toEndWith('my-subscription?invoice=' . $invoice->id)
        ->and(json_encode($messages[0]->data()))->not->toContain('mercadopago');
});

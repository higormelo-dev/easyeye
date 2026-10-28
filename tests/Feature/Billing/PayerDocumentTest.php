<?php

declare(strict_types=1);

use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionStatus};
use App\Models\Billing\SubscriptionChange;
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Queue};

/**
 * CPF/CNPJ do pagador (PayerDocument::assertFor): o Asaas cria o cliente sem documento, mas recusa a ASSINATURA
 * dele (400 invalid_object) — hoje um 502 genérico. A pré-condição devolve 422 `document_required` ANTES de qualquer
 * chamada ao gateway, em todos os caminhos em que a assinatura nasce com o nosso cliente (checkout transparente,
 * cartão do checkout hospedado na contratação, upgrade, cadastro no site e Manager). O gateway manda o canônico
 * (CPF de 11 dígitos; CNPJ de 14 caracteres, com as letras do alfanumérico).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();

    config([
        'billing.enforce_subscription_access'     => true,
        'billing.checkout.lock_wait_seconds'      => 0,
        'billing.gateways.asaas.base_url'         => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'           => '$aact_test',
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public',
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.public_key'     => 'pk_test_easyeye',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly', 'price' => 2878.80]);
    $this->plan = $this->plan->fresh('prices');

    $this->plus = Plan::factory()->create(['name' => 'Plus', 'billing_cycle' => 'monthly', 'price' => 499.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly', 'price' => 499.90]);
    $this->plus = $this->plus->fresh('prices');

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Sem Documento', 'email' => 'financeiro@semdoc.test', 'national_registration' => null, 'cellphone' => '11987654321']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function pdAs(?User $user = null, $member = null): mixed
{
    return test()->actingAs($user ?? test()->user)->withSession(panelSession($member ?? test()->member));
}

/** Asaas do cadastro: cliente + assinatura (1ª parcela) + QR Pix. */
function pdAsaasFake(): void
{
    Http::fake(function (Request $request) {
        $url    = $request->url();
        $method = $request->method();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $method === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => 'cus_doc1']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $method === 'POST') {
            return Http::response(['id' => 'sub_doc1', 'customer' => 'cus_doc1', 'status' => 'ACTIVE', 'billingType' => 'UNDEFINED', 'cycle' => 'MONTHLY', 'value' => 299.9]);
        }

        if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/sub_doc1/payments')) {
            return Http::response(['hasMore' => false, 'data' => [['object' => 'payment', 'id' => 'pay_doc1', 'customer' => 'cus_doc1', 'subscription' => 'sub_doc1', 'value' => 299.9, 'billingType' => 'UNDEFINED', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'invoiceUrl' => 'https://www.asaas.com/i/doc1', 'deleted' => false]]]);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions/sub_doc1') {
            return Http::response(['id' => 'sub_doc1', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]);
        }

        if ($url === 'https://api.asaas.com/v3/payments/pay_doc1/pixQrCode') {
            return Http::response(['encodedImage' => 'iVBORw0KGgoAAAA=', 'payload' => '00020101021226pix-doc-1', 'expirationDate' => '2026-10-08 23:59:59']);
        }

        if ($url === 'https://api.asaas.com/v3/payments/pay_doc1') {
            return Http::response(['object' => 'payment', 'id' => 'pay_doc1', 'customer' => 'cus_doc1', 'subscription' => 'sub_doc1', 'value' => 299.9, 'billingType' => 'UNDEFINED', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'deleted' => false]);
        }

        return Http::response(['errors' => [['code' => 'not_faked', 'description' => $method . ' ' . $url]]], 404);
    });
}

/** Plano pago e vigente no Mercado Pago (20/09 → 20/10), como no upgrade/downgrade de CheckoutReviewFixesTest. */
function pdPaid(Plan $plan): Subscription
{
    return Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for($plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => (float) $plan->priceFor(BillingCycle::Monthly),
        'last_payment_at'         => '2026-09-20 10:00:00',
        'starts_at'               => '2026-09-20 10:00:00',
        'ends_at'                 => '2026-10-20 23:59:59',
        'next_billing_at'         => '2026-10-20 23:59:59',
    ]);
}

describe('contratação nova (Minha assinatura)', function () {
    it('clínica SEM documento: 422 document_required em Pix, boleto e cartão — nenhuma chamada ao gateway e nenhuma assinatura criada', function (string $gateway, array $payload) {
        config(['billing.default_gateway' => $gateway]);
        Http::fake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', ...$payload])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_required')
            ->assertJsonPath('message', __('checkout.errors.document_required'));

        Http::assertNothingSent();
        expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(0);
    })->with([
        'Asaas Pix'                         => ['asaas', ['method' => 'pix']],
        'Asaas boleto'                      => ['asaas', ['method' => 'boleto']],
        'Asaas cartão (checkout hospedado)' => ['asaas', ['method' => 'credit_card', 'card_token' => 'nao-usado']],
        'Mercado Pago Pix'                  => ['mercadopago', ['method' => 'pix']],
        'Pagar.me cartão transparente'      => ['pagarme', ['method' => 'credit_card', 'card_token' => 'token_abc']],
    ]);

    it('a mensagem existe nos dois idiomas, com o motivo e a ação', function () {
        expect(__('checkout.errors.document_required', [], 'pt_BR'))->toBe('Para gerar a cobrança, informe o CPF ou CNPJ da clínica. O meio de pagamento exige o documento — nada foi cobrado.')
            ->and(__('checkout.errors.document_required', [], 'en'))->toBe('To create the charge, enter the clinic CPF or CNPJ. The payment provider requires the payer document — nothing was charged.')
            ->and(__('manager_subscriptions.errors.document_required', [], 'pt_BR'))->toContain('CPF ou CNPJ')
            ->and(__('manager_subscriptions.errors.document_required', [], 'en'))->toContain('CPF or CNPJ');
    });

    it('documento com dígito verificador errado (cadastro antigo) também barra, antes do gateway', function () {
        config(['billing.default_gateway' => 'asaas']);
        $this->clinic->forceFill(['national_registration' => '11222333000182'])->save();
        Http::fake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_required');

        Http::assertNothingSent();
    });

    it('com CPF válido segue: o Asaas recebe o cliente com o cpfCnpj canônico', function () {
        config(['billing.default_gateway' => 'asaas']);
        $this->clinic->forceFill(['national_registration' => '529.982.247-25'])->save();
        pdAsaasFake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'transparent');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/customers' && $r['cpfCnpj'] === '52998224725');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/subscriptions');
    });

    it('CNPJ alfanumérico válido: o Asaas recebe as LETRAS (só sem pontuação), na busca e na criação do cliente', function () {
        config(['billing.default_gateway' => 'asaas']);
        $this->clinic->forceFill(['national_registration' => 'HC.HGX.N73/0001-70'])->save();
        pdAsaasFake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/customers') && str_contains($r->url(), 'cpfCnpj=HCHGXN73000170'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/customers' && $r['cpfCnpj'] === 'HCHGXN73000170');
    });
});

describe('cadastro no site (signup-checkout)', function () {
    it('Contratar agora de uma clínica sem documento: 422 document_required, nada vai ao gateway', function () {
        config(['billing.default_gateway' => 'asaas']);
        Http::fake();
        $user   = User::factory()->unverified()->create();
        $member = createEntityUser($this->clinic, $user, ClientRule::Admin->value, isOwner: true);

        pdAs($user, $member)->postJson(route('signup-checkout.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_required');

        Http::assertNothingSent();
    });
});

describe('troca de plano de quem já paga', function () {
    it('upgrade com cobrança nova, sem documento: 422 document_required, nada cobrado e a assinatura paga intacta', function () {
        config(['billing.default_gateway' => 'mercadopago']);
        $paid = pdPaid($this->plan);
        Http::fake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plus->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_required');

        Http::assertNothingSent();
        expect($paid->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($paid->fresh()->plan_id)->toBe($this->plan->id)
            ->and(SubscriptionChange::query()->where('subscription_id', $paid->id)->count())->toBe(0);
    });

    it('downgrade agendado NÃO cobra nada nem chama o gateway: segue funcionando sem documento', function () {
        config(['billing.default_gateway' => 'mercadopago']);
        $paid = pdPaid($this->plus);
        Http::fake();

        pdAs()->postJson(route('panel.my-subscription.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        Http::assertNothingSent();
        expect(SubscriptionChange::query()->where('subscription_id', $paid->id)->where('change_type', 'downgrade_scheduled')->exists())->toBeTrue();
    });
});

describe('Manager cria assinatura de uma empresa', function () {
    it('empresa sem documento: 422 com o motivo certo (não o 502 do gateway) e nenhuma chamada ao gateway', function () {
        config(['billing.default_gateway' => 'asaas']);
        Http::fake();
        $admin = User::factory()->create();
        $saas  = Entity::factory()->create(['is_client' => false]);
        $link  = createEntityUser($saas, $admin, SaasRule::Admin->value);

        test()->actingAs($admin)->withSession([...panelSession($link), 'selected_entity_is_client' => false])
            ->postJson(route('manager.subscriptions.store'), [
                'entity_id'     => $this->clinic->id,
                'plan_id'       => $this->plan->id,
                'mode'          => 'gateway',
                'billing_cycle' => 'monthly',
                'gateway'       => 'asaas',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_subscriptions.errors.document_required'));

        Http::assertNothingSent();
        expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(0);
    });
});

<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiCreditPurchase;
use App\Enums\{BillingCycle, ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{AuditLog, Entity, Plan, PlanFeature, PlanPrice, Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Queue};
use Illuminate\Testing\TestResponse;

/**
 * PUT payer-document: a clínica SEM CPF/CNPJ utilizável informa o documento no próprio checkout (o 422
 * `document_required`), nunca o troca (trocar um documento válido é do Manager). Painel: panel.my-subscription.*;
 * cadastro no site: signup-checkout.* (clínica recém-criada, antes de confirmar e-mail/WhatsApp). Resposta
 * {data: {document, formatted}}; validação 422 em `document`; `document_already_set` 422 com code; 403 fora do contato
 * de cobrança.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();

    config([
        'billing.enforce_subscription_access' => true,
        'billing.default_gateway'             => 'asaas',
        'billing.checkout.lock_wait_seconds'  => 0,
        'billing.gateways.asaas.base_url'     => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'       => '$aact_test',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    $this->plan = $this->plan->fresh('prices');

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Sem Documento', 'email' => 'financeiro@semdoc.test', 'national_registration' => null, 'cellphone' => '11987654321']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function pduAs(?User $user = null, $member = null): mixed
{
    return test()->actingAs($user ?? test()->user)->withSession(panelSession($member ?? test()->member));
}

function pduPut(string $document, string $route = 'panel.my-subscription.payer-document'): TestResponse
{
    return pduAs()->putJson(route($route), ['document' => $document]);
}

describe('grava o documento da clínica sem documento', function () {
    it('CPF mascarado: 200 {data: {document, formatted}}, grava o canônico e registra na auditoria', function () {
        pduPut('529.982.247-25')
            ->assertOk()
            ->assertExactJson(['data' => ['document' => '52998224725', 'formatted' => '529.982.247-25']]);

        expect($this->clinic->fresh()->national_registration)->toBe('52998224725');

        $log = AuditLog::query()->where('auditable_type', Entity::class)->where('auditable_id', $this->clinic->id)->where('event', 'updated')->latest('id')->first();

        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->user->id)
            ->and(($log->old_values ?? [])['national_registration'] ?? null)->toBeNull()
            ->and($log->new_values['national_registration'] ?? null)->toBe('52998224725');
    });

    it('CNPJ numérico e alfanumérico (minúsculas, com máscara): canônico em maiúsculas, formatted com a máscara', function (string $sent, string $document, string $formatted) {
        pduPut($sent)
            ->assertOk()
            ->assertJsonPath('data.document', $document)
            ->assertJsonPath('data.formatted', $formatted);

        expect($this->clinic->fresh()->national_registration)->toBe($document);
    })->with([
        'numérico'     => ['11.222.333/0001-81', '11222333000181', '11.222.333/0001-81'],
        'alfanumérico' => ['hc.hgx.n73/0001-70', 'HCHGXN73000170', 'HC.HGX.N73/0001-70'],
    ]);

    it('a clínica com documento INVÁLIDO (legado, dígito errado) pode corrigir', function () {
        $this->clinic->forceFill(['national_registration' => '11222333000182'])->save();

        pduPut('11222333000181')->assertOk()->assertJsonPath('data.document', '11222333000181');

        expect($this->clinic->fresh()->national_registration)->toBe('11222333000181');
    });

    it('depois de gravar, a contratação que dava 422 document_required segue e o Asaas recebe o documento', function () {
        Http::fake(function (Request $request) {
            $url    = $request->url();
            $method = $request->method();

            if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
                return $method === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => 'cus_pd1']);
            }

            if ($url === 'https://api.asaas.com/v3/subscriptions' && $method === 'POST') {
                return Http::response(['id' => 'sub_pd1', 'customer' => 'cus_pd1', 'status' => 'ACTIVE', 'billingType' => 'UNDEFINED', 'cycle' => 'MONTHLY', 'value' => 299.9]);
            }

            if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/sub_pd1/payments')) {
                return Http::response(['hasMore' => false, 'data' => [['object' => 'payment', 'id' => 'pay_pd1', 'customer' => 'cus_pd1', 'subscription' => 'sub_pd1', 'value' => 299.9, 'billingType' => 'UNDEFINED', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'invoiceUrl' => 'https://www.asaas.com/i/pd1', 'deleted' => false]]]);
            }

            if ($url === 'https://api.asaas.com/v3/subscriptions/sub_pd1') {
                return Http::response(['id' => 'sub_pd1', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]);
            }

            if ($url === 'https://api.asaas.com/v3/payments/pay_pd1/pixQrCode') {
                return Http::response(['encodedImage' => 'iVBORw0KGgoAAAA=', 'payload' => '00020101021226pix-pd-1', 'expirationDate' => '2026-10-08 23:59:59']);
            }

            if ($url === 'https://api.asaas.com/v3/payments/pay_pd1') {
                return Http::response(['object' => 'payment', 'id' => 'pay_pd1', 'customer' => 'cus_pd1', 'subscription' => 'sub_pd1', 'value' => 299.9, 'billingType' => 'UNDEFINED', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'deleted' => false]);
            }

            return Http::response(['errors' => [['code' => 'not_faked']]], 404);
        });

        $payload = ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'];

        pduAs()->postJson(route('panel.my-subscription.contract'), $payload)->assertStatus(422)->assertJsonPath('code', 'document_required');
        pduPut('529.982.247-25')->assertOk();
        pduAs()->postJson(route('panel.my-subscription.contract'), $payload)->assertOk()->assertJsonPath('data.mode', 'transparent');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/customers' && $r['cpfCnpj'] === '52998224725');
    });
});

describe('recusas', function () {
    it('documento inválido (DV errado, tamanho, todos iguais): 422 em document com a mensagem; nada é gravado', function (string $document) {
        pduPut($document)
            ->assertStatus(422)
            ->assertJsonPath('errors.document.0', __('validation.cpf_cnpj_invalid'))
            ->assertJsonMissingPath('code');

        expect($this->clinic->fresh()->national_registration)->toBeNull();
    })->with([
        'CNPJ DV errado'  => ['11222333000182'],
        'CPF DV errado'   => ['52998224724'],
        'tamanho'         => ['1122233300018'],
        'todos iguais'    => ['11111111111'],
        'alfanum. errado' => ['HCHGXN73000171'],
    ]);

    it('vazio ou ausente: 422 em document (obrigatório)', function () {
        pduAs()->putJson(route('panel.my-subscription.payer-document'), [])->assertStatus(422)->assertJsonValidationErrors(['document']);
        pduAs()->putJson(route('panel.my-subscription.payer-document'), ['document' => ''])->assertStatus(422)->assertJsonValidationErrors(['document']);

        expect($this->clinic->fresh()->national_registration)->toBeNull();
    });

    it('documento de OUTRA clínica: 422 em document com a mensagem de duplicidade', function () {
        Entity::factory()->create(['national_registration' => '11222333000181']);

        pduPut('11.222.333/0001-81')
            ->assertStatus(422)
            ->assertJsonPath('errors.document.0', 'Este CPF ou CNPJ já está cadastrado.');

        expect($this->clinic->fresh()->national_registration)->toBeNull();
    });

    it('corrida: outra clínica pega o mesmo documento entre a validação e a gravação — 422 de duplicidade, não 500', function () {
        $target = $this->clinic->id;
        $fired  = false;

        Entity::saving(function (Entity $entity) use ($target, &$fired) {
            if ((string) $entity->id === (string) $target && ! $fired) {
                $fired = true;
                Entity::factory()->create(['national_registration' => '11222333000181']);
            }
        });

        pduPut('11222333000181')
            ->assertStatus(422)
            ->assertJsonPath('errors.document.0', 'Este CPF ou CNPJ já está cadastrado.');

        expect($fired)->toBeTrue()
            ->and($this->clinic->fresh()->national_registration)->toBeNull();
    });

    it('clínica que JÁ tem documento válido: 422 document_already_set, o documento NÃO muda e nada é auditado', function () {
        $this->clinic->forceFill(['national_registration' => '11222333000181'])->save();
        $before = AuditLog::query()->where('auditable_type', Entity::class)->where('auditable_id', $this->clinic->id)->count();

        pduPut('52998224725')
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_already_set')
            ->assertJsonPath('message', __('checkout.errors.document_already_set'));

        expect($this->clinic->fresh()->national_registration)->toBe('11222333000181')
            ->and(AuditLog::query()->where('auditable_type', Entity::class)->where('auditable_id', $this->clinic->id)->count())->toBe($before);
    });

    it('quem não é contato de cobrança (secretária): 403 forbidden e nada é gravado', function () {
        $secretary = User::factory()->create();
        $member    = createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);

        pduAs($secretary, $member)->putJson(route('panel.my-subscription.payer-document'), ['document' => '52998224725'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        expect($this->clinic->fresh()->national_registration)->toBeNull();
    });

    it('financeiro também pode (é contato de cobrança)', function () {
        $financial = User::factory()->create();
        $member    = createEntityUser($this->clinic, $financial, ClientRule::Financial->value);

        pduAs($financial, $member)->putJson(route('panel.my-subscription.payer-document'), ['document' => '52998224725'])->assertOk();
        expect($this->clinic->fresh()->national_registration)->toBe('52998224725');
    });

    it('não há como escolher a clínica pelo corpo: entity_id/id enviados são ignorados', function () {
        $other = Entity::factory()->create(['national_registration' => null]);

        pduAs()->putJson(route('panel.my-subscription.payer-document'), ['document' => '52998224725', 'entity_id' => $other->id, 'id' => $other->id])->assertOk();

        expect($other->fresh()->national_registration)->toBeNull()
            ->and($this->clinic->fresh()->national_registration)->toBe('52998224725');
    });
});

describe('cadastro no site (signup-checkout.payer-document)', function () {
    it('clínica recém-criada, antes de confirmar e-mail/WhatsApp: grava pelo caminho do signup', function () {
        $user   = User::factory()->unverified()->create();
        $member = createEntityUser($this->clinic, $user, ClientRule::Admin->value, isOwner: true);

        pduAs($user, $member)->putJson(route('signup-checkout.payer-document'), ['document' => '529.982.247-25'])
            ->assertOk()
            ->assertExactJson(['data' => ['document' => '52998224725', 'formatted' => '529.982.247-25']]);

        expect($this->clinic->fresh()->national_registration)->toBe('52998224725');
    });

    it('clínica que já pagou não usa o caminho do cadastro: 403 signup_only', function () {
        Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'billing_cycle'   => BillingCycle::Monthly,
            'status'          => SubscriptionStatus::Active,
            'last_payment_at' => '2026-09-20 10:00:00',
        ]);

        pduAs()->putJson(route('signup-checkout.payer-document'), ['document' => '52998224725'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'signup_only');

        expect($this->clinic->fresh()->national_registration)->toBeNull();
    });
});

describe('pacote de créditos de IA (mesma guarda)', function () {
    it('clínica sem documento: comprar pacote dá 422 document_required ANTES de qualquer pedido ou chamada ao gateway', function () {
        PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
        Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'billing_cycle'   => BillingCycle::Monthly,
            'status'          => SubscriptionStatus::Active,
            'billing_state'   => 'paid',
            'last_payment_at' => '2026-09-20 10:00:00',
            'starts_at'       => '2026-09-20 10:00:00',
            'ends_at'         => '2026-10-20 23:59:59',
            'next_billing_at' => '2026-10-20 23:59:59',
        ]);
        Http::fake();

        pduAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'premium', 'method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_required');

        Http::assertNothingSent();
        expect(AiCreditPurchase::query()->count())->toBe(0); // nenhum pedido aberto
    });
});

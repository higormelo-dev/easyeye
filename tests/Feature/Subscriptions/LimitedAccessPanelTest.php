<?php

declare(strict_types=1);

use App\Enums\Billing\InvoiceStatus;
use App\Enums\{ClientRule, FeatureKey, ScheduleSituation, SubscriptionStatus};
use App\Enums\PaymentMethod;
use App\Exceptions\FeatureDeniedException;
use App\Models\Billing\Invoice;
use App\Models\{Covenant, Entity, Patient, People, Plan, PlanFeature, Subscription, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\FeatureGateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Painel no acesso limitado (cliente pagante em atraso, D+3 a D+6): IA e
 * módulo financeiro bloqueados; agenda, pacientes e prontuário seguem. A
 * tela /subscription/expired explica o modo limitado e oferece "Pagar agora";
 * o AppLayout recebe o aviso da situação da assinatura.
 */
beforeEach(function () {
    config(['billing.enforce_subscription_access' => true]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 09:00:00'));

    $this->plan = Plan::factory()->create(['active' => true, 'name' => 'Pro']);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Régua']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function limitedPanelAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->member));
}

/** Cliente pagante com a cobrança de 06/10 vencida: em 10/10 está no D+4 (acesso limitado). */
function limitedPanelOverdue(array $attributes = []): Subscription
{
    return Subscription::factory()->gateway()->for(test()->clinic)->for(test()->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'past_due',
        'amount'          => 299.90,
        'last_payment_at' => '2026-09-06 10:00:00',
        'ends_at'         => '2026-10-06 23:59:59',
        'next_billing_at' => '2026-10-06 23:59:59',
        'past_due_at'     => '2026-10-06 23:59:59',
        ...$attributes,
    ]);
}

function limitedPanelInvoice(Subscription $subscription, array $attributes = []): Invoice
{
    return Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-' . strtoupper(str()->random(10)),
        'period_start'    => '2026-10-06',
        'period_end'      => '2026-11-06',
        'due_at'          => '2026-10-06 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Overdue->value,
        'payment_url'     => 'https://www.asaas.com/i/pay_regua_001',
        ...$attributes,
    ]);
}

describe('rotas no acesso limitado', function () {
    beforeEach(fn () => limitedPanelOverdue());

    it('IA e módulo financeiro devolvem 402 com a mensagem própria (JSON)', function (string $method, string $route) {
        limitedPanelAs()->json($method, route($route))
            ->assertStatus(402)
            ->assertJsonPath('message', __('subscriptions.access_limited'))
            ->assertJsonPath('access_level', 'limited');
    })->with([
        'uso de IA'           => ['GET', 'panel.ai-runs.index'],
        'nova análise de IA'  => ['POST', 'panel.ai-runs.store'],
        'estimativa de IA'    => ['POST', 'panel.ai-runs.estimate'],
        'prompts de IA'       => ['GET', 'panel.setting.ai-prompts.index'],
        'fluxo de caixa'      => ['GET', 'panel.financial.cash-flow.index'],
        'faturamento TISS'    => ['GET', 'panel.financial.billing.index'],
        'painel financeiro'   => ['GET', 'panel.financial.bi.index'],
        'relatório de caixa'  => ['GET', 'panel.financial.reports.cash-flow'],
        'relatório convênios' => ['GET', 'panel.financial.reports.covenants'],
        'repasses do médico'  => ['GET', 'panel.my-payouts.index'],
    ]);

    it('navegação no financeiro vai para a tela do acesso limitado', function () {
        limitedPanelAs()->get(route('panel.financial.cash-flow.index'))
            ->assertRedirect(route('subscription.expired'));
    });

    it('agenda, pacientes, prontuário e o resto do painel seguem liberados', function () {
        $patient = Patient::create([
            'entity_id'   => $this->clinic->id,
            'person_id'   => People::factory()->create()->id,
            'covenant_id' => Covenant::factory()->create()->id,
            'active'      => true,
        ]);

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk();
        limitedPanelAs()->get(route('panel.schedules.index'))->assertOk();
        limitedPanelAs()->getJson(route('panel.patients.index'))->assertOk();
        limitedPanelAs()->getJson(route('panel.patients.medicalrecords.ajaxlist', $patient))->assertOk();
        limitedPanelAs()->get(route('panel.profile.edit'))->assertOk();
    });
});

it('o menu de IA some no acesso limitado; o financeiro fica e leva à explicação', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    limitedPanelOverdue(['past_due_at' => '2026-10-08 23:59:59', 'ends_at' => '2026-10-08 23:59:59']);

    $menu = fn () => limitedPanelAs()->get(route('panel.dashboard'))->assertOk()->inertiaProps('nav');

    // D+2: acesso total — IA no menu.
    expect(collect($menu())->pluck('key')->all())->toContain('ai', 'financial');

    // D+3: acesso limitado — a IA some.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));
    expect(collect($menu())->pluck('key')->all())->toContain('financial')->not->toContain('ai');
});

it('em atraso antes do D+3 (acesso total com aviso) o financeiro segue liberado', function () {
    limitedPanelOverdue(['past_due_at' => '2026-10-08 23:59:59', 'ends_at' => '2026-10-08 23:59:59']);

    limitedPanelAs()->getJson(route('panel.financial.cash-flow.index'))->assertOk();
});

it('no D+7 o bloqueio é total', function () {
    limitedPanelOverdue(['past_due_at' => '2026-10-03 23:59:59', 'ends_at' => '2026-10-03 23:59:59']);

    limitedPanelAs()->get(route('panel.schedules.index'))->assertRedirect(route('subscription.expired'));
    limitedPanelAs()->getJson(route('panel.dashboard'))
        ->assertStatus(402)
        ->assertJsonPath('message', __('subscriptions.access_blocked'));
});

describe('tela /subscription/expired', function () {
    it('modo limitado: explica o bloqueio, oferece "Pagar agora" e volta ao painel', function () {
        limitedPanelInvoice(limitedPanelOverdue());

        limitedPanelAs()->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Panel/SubscriptionExpired')
                ->where('mode', 'limited')
                ->where('limited.days_overdue', 4)
                ->where('limited.blocked_date', '2026-10-13')
                ->where('payment.url', 'https://www.asaas.com/i/pay_regua_001')
                ->where('payment.amount', 299.9)
                ->where('payment.due_date', '2026-10-06')
                ->where('urls.dashboard', route('panel.dashboard'))
                ->where('plans', [])
                ->where('t.heading_limited', __('subscriptions.expired_page.heading_limited')));
    });

    it('contratação com a 1ª cobrança vencida: sem acesso, mas com "Pagar agora" (o pagamento ativa)', function () {
        $pending = Subscription::factory()->for($this->clinic)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'pending_activation',
            'gateway'         => 'asaas',
            'last_payment_at' => null,
            'next_billing_at' => '2026-10-08 23:59:59',
            'ends_at'         => '2026-10-08 23:59:59',
        ]);
        limitedPanelInvoice($pending, ['status' => InvoiceStatus::Pending->value, 'due_at' => '2026-10-08 23:59:59', 'payment_url' => 'https://www.asaas.com/i/first_001']);

        limitedPanelAs()->get(route('panel.dashboard'))->assertRedirect(route('subscription.expired'));

        limitedPanelAs()->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'blocked')
                ->where('lastSubscription.status', 'past_due')
                ->where('payment.url', 'https://www.asaas.com/i/first_001')
                ->where('payment.due_date', '2026-10-08'));
    });

    it('assinatura encerrada não oferece pagamento (pagar não religaria o acesso)', function () {
        $expired = limitedPanelOverdue(['status' => SubscriptionStatus::Expired, 'billing_state' => 'expired']);
        limitedPanelInvoice($expired);

        limitedPanelAs()->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'blocked')->where('payment', null));
    });

    it('acesso total volta para o painel', function () {
        limitedPanelOverdue(['past_due_at' => '2026-10-09 23:59:59', 'ends_at' => '2026-10-09 23:59:59']);

        limitedPanelAs()->get(route('subscription.expired'))->assertRedirect(route('panel.dashboard'));
    });
});

describe('aviso da assinatura no painel (subscriptionBanner)', function () {
    it('pagamento pendente da contratação: vence em DD/MM, sem fechar, com "Pagar agora"', function () {
        $pending = Subscription::factory()->for($this->clinic)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'pending_activation',
            'gateway'         => 'asaas',
            'last_payment_at' => null,
            'next_billing_at' => '2026-10-13 23:59:59',
            'ends_at'         => '2026-10-13 23:59:59',
        ]);
        limitedPanelInvoice($pending, ['status' => InvoiceStatus::Pending->value, 'due_at' => '2026-10-13 23:59:59', 'payment_url' => 'https://www.asaas.com/i/first_002']);

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'first_payment_pending')
                ->where('subscriptionBanner.due_date', '2026-10-13')
                ->where('subscriptionBanner.dismissible', false)
                ->where('subscriptionBanner.payment_url', 'https://www.asaas.com/i/first_002')
                ->where('subscriptionBanner.t.pay_now', __('subscriptions.banner.pay_now')));
    });

    it('em atraso com acesso total: dias de atraso e quando vira parcial/total', function () {
        limitedPanelInvoice(limitedPanelOverdue(['past_due_at' => '2026-10-08 23:59:59', 'ends_at' => '2026-10-08 23:59:59']), ['due_at' => '2026-10-08 23:59:59']);

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'overdue')
                ->where('subscriptionBanner.level', 'full')
                ->where('subscriptionBanner.days_overdue', 2)
                ->where('subscriptionBanner.limited_date', '2026-10-11')
                ->where('subscriptionBanner.blocked_date', '2026-10-15')
                ->where('subscriptionBanner.dismissible', false)
                ->where('subscriptionBanner.payment_url', 'https://www.asaas.com/i/pay_regua_001'));
    });

    it('acesso limitado', function () {
        limitedPanelOverdue();

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'limited')
                ->where('subscriptionBanner.level', 'limited')
                ->where('subscriptionBanner.days_overdue', 4)
                ->where('subscriptionBanner.blocked_date', '2026-10-13')
                ->where('subscriptionBanner.payment_url', null)
                ->where('subscriptionBanner.dismissible', false));
    });

    it('trial terminando em até 3 dias (pode fechar); antes disso, nada', function (int $days, ?string $kind) {
        Subscription::factory()->trial($days)->for($this->clinic)->for($this->plan)->create();

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $kind === null
                ? $page->where('subscriptionBanner', null)
                : $page->where('subscriptionBanner.kind', $kind)
                    ->where('subscriptionBanner.days_left', $days)
                    ->where('subscriptionBanner.dismissible', true));
    })->with([
        'faltam 2 dias' => [2, 'trial_ending'],
        'faltam 3 dias' => [3, 'trial_ending'],
        'faltam 4 dias' => [4, null],
    ]);

    it('cortesia ou plano em dia: sem aviso', function () {
        Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create(['ends_at' => '2026-12-31 23:59:59']);

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner', null));
    });

    it('só a empresa da sessão: atraso de outra clínica não aparece', function () {
        Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create(['ends_at' => '2026-12-31 23:59:59']);

        $other                = Entity::factory()->make(['is_client' => true]);
        $other->skipAutoTrial = true;
        $other->save();
        Subscription::factory()->gateway()->for($other)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'last_payment_at' => '2026-09-06 10:00:00',
            'past_due_at'     => '2026-10-06 23:59:59',
            'ends_at'         => '2026-10-06 23:59:59',
        ]);

        limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner', null));
    });
});

it('contratação aguardando o 1º pagamento: o painel mostra o plano contratado', function () {
    // O trial foi substituído pela contratação (fase 3 da ativação).
    Subscription::factory()->trial(5)->for($this->clinic)->for(Plan::factory()->create(['name' => 'Trial']))->create([
        'status'       => SubscriptionStatus::Cancelled,
        'cancelled_at' => now(),
    ]);
    Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'pending_activation',
        'last_payment_at' => null,
        'next_billing_at' => '2026-10-13 23:59:59',
        'ends_at'         => '2026-10-13 23:59:59',
    ]);

    limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.entity.plan', 'Pro'));
});

it('a assinatura da empresa é consultada uma vez por request do painel, e nunca reaproveitada entre requests', function () {
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    $subscription = limitedPanelOverdue(['past_due_at' => '2026-10-08 23:59:59', 'ends_at' => '2026-10-08 23:59:59']);

    // Middleware, aviso do painel, menu (gate de recursos) e plano no cabeçalho.
    DB::flushQueryLog();
    DB::enableQueryLog();

    limitedPanelAs()->get(route('panel.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'overdue')
            ->where('auth.entity.plan', 'Pro'));

    $lookups = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'from "subscriptions"') && str_contains($sql, '"trial_ends_at" >'));
    DB::disableQueryLog();

    expect($lookups)->toHaveCount(1);

    // Próximo request: nada guardado do anterior.
    $subscription->update(['status' => SubscriptionStatus::Expired]);

    limitedPanelAs()->get(route('panel.dashboard'))->assertRedirect(route('subscription.expired'));
});

it('IA negada pelo gate no acesso limitado responde igual ao middleware (402 + access_level)', function () {
    limitedPanelOverdue();

    // Fora das rotas bloqueadas pelo middleware: o próprio gate nega.
    $middleware = limitedPanelAs()->getJson(route('panel.ai-runs.index'))->assertStatus(402)->json();
    $gate       = (new FeatureDeniedException(
        FeatureKey::HasAiChatAssistant,
        app(FeatureGateService::class)->status((string) $this->clinic->id, FeatureKey::HasAiChatAssistant),
    ))->render(Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));

    expect($gate->getStatusCode())->toBe(402)
        ->and(json_decode($gate->getContent(), true)['message'])->toBe($middleware['message'])
        ->and(json_decode($gate->getContent(), true)['access_level'])->toBe($middleware['access_level']);
});

it('com "Atendido exige caixa", o lançamento no caixa pela agenda segue liberado no acesso limitado e o atendimento fecha; as telas do financeiro não', function () {
    limitedPanelOverdue();
    $this->clinic->update(['requires_cash_to_complete' => true]);
    ['schedule' => $schedule] = createScheduleForEntity($this->clinic, ['situation' => ScheduleSituation::InProgress->value]);

    // Sem o lançamento, a trava de caixa (regra clínica) segura o Atendido.
    limitedPanelAs()->patchJson(route('panel.schedules.situation', $schedule), ['situation' => ScheduleSituation::Attended->value])
        ->assertStatus(422)
        ->assertJsonPath('requires_cash_entry', true);

    // "Lançar no caixa" do atendimento: operação clínica, liberada no limitado.
    limitedPanelAs()->postJson(route('panel.schedules.cash-entry.store', $schedule), [
        'entry_date'     => now()->toDateString(),
        'description'    => 'Consulta',
        'payment_method' => PaymentMethod::Cash->value,
        'amount'         => 250.00,
    ])->assertOk();

    expect($schedule->financialEntries()->count())->toBe(1);

    limitedPanelAs()->patchJson(route('panel.schedules.situation', $schedule), ['situation' => ScheduleSituation::Attended->value])
        ->assertOk()
        ->assertJsonPath('situation', ScheduleSituation::Attended->value);

    // As telas do módulo financeiro seguem bloqueadas.
    limitedPanelAs()->getJson(route('panel.financial.cash-flow.index'))
        ->assertStatus(402)
        ->assertJsonPath('access_level', 'limited');
});

describe('/subscription/expired passa pelos mesmos controles de segurança do painel', function () {
    it('empresa que exige 2FA: sessão sem o 2º fator vai para a verificação, sem valor nem link da fatura', function () {
        limitedPanelInvoice(limitedPanelOverdue());
        $this->clinic->update(['requires_two_factor' => true]);
        $this->user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $response = limitedPanelAs()->get(route('subscription.expired'));

        $response->assertRedirect(route('security.two-factor.verify'));
        expect($response->getContent())->not->toContain('pay_regua_001');

        // Com o 2º fator verificado na sessão, a tela abre normalmente.
        limitedPanelAs()->withSession([...panelSession($this->member), 'two_factor_verified_at' => now()->toIso8601String()])
            ->get(route('subscription.expired'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Panel/SubscriptionExpired')->where('mode', 'limited'));
    });

    it('WhatsApp do responsável ainda não confirmado: vai para a confirmação, como no painel', function () {
        limitedPanelInvoice(limitedPanelOverdue());
        $this->user->forceFill(['phone' => '11988887777', 'phone_verified_at' => null])->save();
        WhatsAppSetting::create([
            'entity_id'     => null,
            'active'        => true,
            'webhook_token' => WhatsAppSetting::generateWebhookToken(),
            'credentials'   => ['instance_id' => 'test-instance', 'instance_token' => 'test-token', 'client_token' => 'test-client'],
        ]);

        limitedPanelAs()->get(route('subscription.expired'))->assertRedirect(route('phone.verification.notice'));
    });
});

/** Outro usuário da clínica, com o perfil dado, navegando o painel. */
function limitedPanelAsMember(ClientRule $rule, bool $isOwner = false): mixed
{
    $user   = User::factory()->create();
    $member = createEntityUser(test()->clinic, $user, $rule->value, isOwner: $isOwner);

    return test()->actingAs($user)->withSession(panelSession($member));
}

describe('valor e link da fatura só para admin, financeiro e dono (LGPD)', function () {
    beforeEach(function () {
        // Em atraso com acesso total (D+2): aviso no painel com a fatura em aberto.
        limitedPanelInvoice(limitedPanelOverdue(['past_due_at' => '2026-10-08 23:59:59', 'ends_at' => '2026-10-08 23:59:59']), ['due_at' => '2026-10-08 23:59:59']);
    });

    it('médico e secretária veem o aviso sem valor nem link, com a orientação de procurar o administrador', function (ClientRule $rule) {
        limitedPanelAsMember($rule)->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'overdue')
                ->where('subscriptionBanner.can_pay', false)
                ->where('subscriptionBanner.payment_url', null)
                ->where('subscriptionBanner.amount', null)
                ->where('subscriptionBanner.t.ask_admin', __('subscriptions.banner.ask_admin')));
    })->with([
        'médico'     => [ClientRule::Doctor],
        'secretária' => [ClientRule::Secretary],
    ]);

    it('admin, financeiro e dono (com outro perfil) veem valor e link', function (ClientRule $rule, bool $isOwner) {
        limitedPanelAsMember($rule, $isOwner)->get(route('panel.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.can_pay', true)
                ->where('subscriptionBanner.payment_url', 'https://www.asaas.com/i/pay_regua_001')
                ->where('subscriptionBanner.amount', 299.9));
    })->with([
        'admin'         => [ClientRule::Admin, false],
        'financeiro'    => [ClientRule::Financial, false],
        'dono (médico)' => [ClientRule::Doctor, true],
    ]);

    it('tela de acesso limitado: secretária sem valor nem link; admin com "Pagar agora"', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00')); // D+4: acesso limitado

        limitedPanelAsMember(ClientRule::Secretary)->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'limited')
                ->where('canPay', false)
                ->where('payment', null)
                ->where('t.ask_admin', __('subscriptions.expired_page.ask_admin')));

        limitedPanelAs()->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canPay', true)
                ->where('payment.url', 'https://www.asaas.com/i/pay_regua_001'));
    });
});

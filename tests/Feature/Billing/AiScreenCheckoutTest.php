<?php

declare(strict_types=1);

use App\Enums\{BillingCycle, ClientRule, FeatureKey, SubscriptionStatus};
use App\Http\Middleware\{CheckoutContentSecurityPolicy, HandleInertiaRequests};
use App\Models\Billing\Invoice;
use App\Models\{Entity, Plan, PlanFeature, PlanPrice, Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http, Queue};

/**
 * Rodada 5 — A2 (CSP na tela de IA, onde o pacote de créditos é pago com o
 * SDK de cartão) e A4 (pedido pendente na tela de IA: continuar ou
 * descartar).
 *
 * A CSP do documento continua valendo enquanto o usuário navega pelo painel
 * (SPA): a política precisa liberar o que o painel inteiro usa — storage
 * (S3/MinIO/public), blob:, ViaCEP, Reverb — sem abrir script-src.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();

    config([
        'billing.default_gateway'                 => 'mercadopago',
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public',
        'billing.csp.mode'                        => 'enforce',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($this->plan)->create();

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.test', 'national_registration' => '11222333000181']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);

    Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-10-01 10:00:00',
        'starts_at'               => '2026-09-01 10:00:00',
        'ends_at'                 => '2026-11-01 23:59:59',
        'next_billing_at'         => '2026-11-01 23:59:59',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function r5AiAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->member));
}

/** @return array<string, list<string>> diretiva → fontes */
function r5CspDirectives(string $policy): array
{
    return collect(explode(';', $policy))
        ->map(fn (string $d) => preg_split('/\s+/', trim($d)))
        ->filter(fn (array $parts) => ($parts[0] ?? '') !== '')
        ->mapWithKeys(fn (array $parts) => [$parts[0] => array_slice($parts, 1)])
        ->all();
}

describe('A2 — CSP na tela de IA', function () {
    it('a tela de IA (Uso e créditos) sai com a Content-Security-Policy do checkout', function () {
        $response = r5AiAs()->get(route('panel.ai-runs.index'))->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $csp    = r5CspDirectives($policy);

        expect($policy)->not->toBe('')
            // SDKs de cartão dos gateways liberados — e nada de host arbitrário em script-src.
            ->and($csp['script-src'])->toContain('https://sdk.mercadopago.com', 'https://js.stripe.com', 'https://checkout.pagar.me', 'https://assets.pagseguro.com.br')
            ->and($csp['script-src'])->not->toContain('https:', '*', 'http:', 'data:', 'blob:')
            ->and($csp['object-src'])->toBe(["'none'"])
            ->and($csp['form-action'])->toBe(["'self'"]);
    });

    it('chegando de OUTRA tela pelo SPA, força carregamento completo (senão a CSP não vale); na própria tela segue SPA', function () {
        $url     = route('panel.ai-runs.index');
        $version = app(HandleInertiaRequests::class)->version(request());
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];

        // Veio do dashboard (visita Inertia): 409 + X-Inertia-Location = recarga completa com o cabeçalho.
        r5AiAs()->get($url, $inertia + ['Referer' => url('/panel')])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $url);

        // Sem Referer (cliente de API/teste): não força.
        r5AiAs()->get($url, $inertia)->assertOk();

        // Filtro/recarga na própria tela e recarga parcial: continua SPA.
        r5AiAs()->get($url . '?period=30', $inertia + ['Referer' => $url])->assertOk()->assertHeader('X-Inertia', 'true');
        r5AiAs()->get($url, $inertia + ['Referer' => url('/panel'), 'X-Inertia-Partial-Component' => 'Panel/AI/Index', 'X-Inertia-Partial-Data' => 'runs'])
            ->assertOk();

        // CSP desligada: não interfere na navegação.
        config(['billing.csp.mode' => 'off']);
        r5AiAs()->get($url, $inertia + ['Referer' => url('/panel')])->assertOk();
    });

    it('report-only também vale na tela de IA; off não manda cabeçalho', function () {
        config(['billing.csp.mode' => 'report-only']);
        $response = r5AiAs()->get(route('panel.ai-runs.index'))->assertOk();
        expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
            ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();

        config(['billing.csp.mode' => 'off']);
        $response = r5AiAs()->get(route('panel.ai-runs.index'))->assertOk();
        expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('a política libera o que o painel carrega depois da navegação SPA: storage, blob:, mídia, ViaCEP e Reverb', function () {
        config([
            'filesystems.disks.public.url'            => 'http://localhost:8085/storage',
            'filesystems.disks.s3.url'                => null,
            'filesystems.disks.s3.endpoint'           => 'http://localhost:9000',
            'filesystems.disks.s3.bucket'             => 'easyeye',
            'broadcasting.connections.reverb.options' => ['host' => 'ws.easyeye.app', 'port' => 443, 'scheme' => 'https'],
        ]);

        $csp = r5CspDirectives(CheckoutContentSecurityPolicy::policy());

        // Imagens/PDF/vídeo do storage (URL assinada do MinIO/S3, disco public).
        foreach (['img-src', 'media-src', 'frame-src', 'connect-src'] as $directive) {
            expect($csp[$directive])->toContain('http://localhost:9000', 'http://*.localhost:9000', 'http://localhost:8085');
        }

        expect($csp['media-src'])->toContain("'self'", 'blob:', 'data:')
            ->and($csp['frame-src'])->toContain("'self'", 'blob:')
            ->and($csp['img-src'])->toContain('blob:', 'data:')
            ->and($csp['worker-src'])->toContain('blob:')
            // CEP → endereço (cadastro de paciente/médico/empresa).
            ->and($csp['connect-src'])->toContain('https://viacep.com.br', 'wss://ws.easyeye.app', 'https://ws.easyeye.app')
            ->and($csp['script-src'])->not->toContain('http://localhost:9000');
    });

    it('S3 na AWS sem endpoint: o host do bucket; Reverb sem host: o do próprio site', function () {
        config([
            'filesystems.disks.s3.url'                => null,
            'filesystems.disks.s3.endpoint'           => null,
            'filesystems.disks.s3.bucket'             => 'easyeye-prod',
            'filesystems.disks.s3.region'             => 'sa-east-1',
            'broadcasting.connections.reverb.key'     => 'reverb-key',
            'broadcasting.connections.reverb.options' => ['host' => null, 'port' => 8080, 'scheme' => 'http'],
        ]);

        $response = r5AiAs()->get(route('panel.ai-runs.index'))->assertOk();
        $csp      = r5CspDirectives((string) $response->headers->get('Content-Security-Policy'));
        $host     = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        expect($csp['img-src'])->toContain('https://easyeye-prod.s3.sa-east-1.amazonaws.com')
            ->and($csp['frame-src'])->toContain('https://easyeye-prod.s3.sa-east-1.amazonaws.com')
            ->and($csp['connect-src'])->toContain("ws://{$host}:8080");
    });
});

describe('A4 — pedido de créditos pendente na tela de IA', function () {
    it('as opções do pacote trazem o pedido em aberto (continuar ou descartar); descartado, some', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response([
            'id'                   => 9101,
            'status'               => 'pending',
            'payment_method_id'    => 'pix',
            'transaction_amount'   => 249.90,
            'date_of_expiration'   => '2026-10-08T23:59:59.000-03:00',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020101pix-9101', 'qr_code_base64' => 'iVBOR=', 'ticket_url' => 'https://www.mercadopago.com.br/payments/9101/ticket']],
        ], 201)]);

        r5AiAs()->postJson(route('panel.my-subscription.ai-credits.purchase'), ['package_code' => 'operational', 'method' => 'pix'])->assertOk();
        $invoice = Invoice::query()->where('billing_reason', Invoice::BILLING_REASON_AI_CREDIT_PACK)->sole();

        r5AiAs()->getJson(route('panel.my-subscription.ai-credits.options'))
            ->assertOk()
            ->assertJsonCount(1, 'data.open_orders')
            ->assertJsonPath('data.open_orders.0.id', $invoice->id)
            ->assertJsonPath('data.open_orders.0.can_pay', true)
            ->assertJsonPath('data.open_orders.0.can_discard', true)
            ->assertJsonPath('data.open_orders.0.ai_credit_pack.credits', 100)
            ->assertJsonPath('data.open_orders.0.payment.gateway', 'mercadopago');

        Http::fake(['https://api.mercadopago.com/v1/payments/9101' => Http::response(['id' => 9101, 'status' => 'cancelled'])]);

        r5AiAs()->deleteJson(route('panel.my-subscription.ai-credits.discard', ['invoice' => $invoice->id]))->assertOk();

        r5AiAs()->getJson(route('panel.my-subscription.ai-credits.options'))
            ->assertOk()
            ->assertJsonCount(0, 'data.open_orders');
    });
});

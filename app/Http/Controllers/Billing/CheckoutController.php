<?php

namespace App\Http\Controllers\Billing;

use App\Enums\Billing\CheckoutMethod;
use App\Enums\BillingCycle;
use App\Exceptions\Billing\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\{AiCreditPackPurchaseRequest, CheckoutCardRequest, CheckoutContractRequest, PayerDocumentRequest};
use App\Models\{Entity, Plan, PlanPrice, User};
use App\Services\Auth\PhoneVerificationService;
use App\Services\Billing\{AiCreditPackCheckoutService, CheckoutFraudGuard, CheckoutService, HostedCheckoutService, PayerDocumentService};
use App\Support\Site\SiteContent;
use Closure;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

/**
 * Checkout transparente da assinatura (JSON para a tela "Minha assinatura",
 * o aviso de pagamento, /subscription/expired e o cadastro no site). A
 * clínica vem da sessão (middleware billing.contact): fatura de outra
 * clínica é 404. Regras e gateway: CheckoutService.
 */
class CheckoutController extends Controller
{
    /** O que o checkout hospedado devolve em `checkout_return` (HostedCheckoutService::callbackUrl). */
    private const RETURN_RESULTS = ['success', 'cancel', 'expired'];

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CheckoutFraudGuard $fraud,
        private readonly AiCreditPackCheckoutService $aiPacks,
        private readonly PayerDocumentService $payerDocument,
    ) {
    }

    /** Página "Minha assinatura" (Panel/MySubscription/Index). */
    public function page(Request $request): Response
    {
        return Inertia::render('Panel/MySubscription/Index', [
            'checkout'  => $this->checkout->summary($this->entity($request)),
            'aiCredits' => $this->aiPacks->options($this->entity($request)),
            't'         => trans('checkout'),
        ]);
    }

    /**
     * Volta do checkout hospedado do cadastro no site: PÁGINA (a summary é JSON). A URL só diz por onde o cliente
     * voltou; o estado vem do banco (fatura e assinatura da clínica da sessão). Parâmetros inválidos = 404.
     */
    public function signupReturn(Request $request): Response
    {
        $query = Validator::make($request->query(), [
            'checkout_return'  => ['required', 'string', Rule::in(self::RETURN_RESULTS)],
            'checkout_invoice' => ['required', 'uuid'],
        ]);

        abort_if($query->fails(), 404);

        $state = $this->checkout->signupReturnState($this->entity($request), (string) $query->validated()['checkout_invoice']);
        $user  = $request->user();

        // A mesma ordem do painel: e-mail (`verified`) → WhatsApp (`phone.verified`) → painel.
        [$nextStep, $nextUrl] = match (true) {
            ! $user->hasVerifiedEmail()    => ['verify_email', route('verification.notice', absolute: false)],
            $this->phoneGateApplies($user) => ['verify_phone', route('phone.verification.notice', absolute: false)],
            default                        => ['panel', route('panel.dashboard', absolute: false)],
        };

        return Inertia::render('Auth/SignupCheckoutReturn', [
            'appName'             => config('app.name', 'EasyEye'),
            't'                   => SiteContent::translations(),
            'tCheckout'           => trans('checkout'),
            'tAuth'               => trans('auth'),
            'status'              => (string) $query->validated()['checkout_return'],
            'invoice'             => $state['invoice'],
            'subscription_active' => $state['subscription_active'],
            'next_step'           => $nextStep,
            'next_url'            => $nextUrl,
            'summary_url'         => route('signup-checkout.summary', absolute: false),
            'routes'              => [
                'register' => route('register'),
                'siteHome' => route('site.home'),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkout->summary($this->entity($request))]);
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id'       => ['required', 'uuid'],
            'billing_cycle' => ['required', 'string', Rule::in(PlanPrice::sellableCycleValues())],
        ]);

        $plan = Plan::active()->whereKey($validated['plan_id'])->firstOrFail();

        return response()->json(['data' => $this->checkout->contractOptions($this->entity($request), $plan, BillingCycle::from($validated['billing_cycle']))]);
    }

    /** Só leitura: nunca emite nem cancela cobrança (sem cobrança na forma: issue_required). */
    public function instructions(Request $request, string $invoice): JsonResponse
    {
        $validated = $request->validate(['method' => ['required', 'string', Rule::in(CheckoutMethod::values())]]);

        return response()->json(['data' => $this->checkout->instructions($this->entity($request), $invoice, CheckoutMethod::from($validated['method']), $this->returnTarget($request))]);
    }

    /** Emite a cobrança da fatura na forma pedida (POST, limite de pagamento). */
    public function issueCharge(Request $request, string $invoice): JsonResponse
    {
        $validated = $request->validate(['method' => ['required', 'string', Rule::in(CheckoutMethod::values())]]);
        $key       = $request->header('Idempotency-Key');

        return response()->json(['data' => $this->checkout->issueCharge(
            $this->entity($request),
            $invoice,
            CheckoutMethod::from($validated['method']),
            is_string($key) && $key !== '' ? mb_substr($key, 0, 100) : null,
            $this->returnTarget($request),
        )]);
    }

    public function payWithCard(CheckoutCardRequest $request, string $invoice): JsonResponse
    {
        return $this->guardCard($request, fn () => $this->checkout->payInvoiceWithCard($this->entity($request), $invoice, $request->cardInput(), $request->idempotencyKey(), $this->returnTarget($request)));
    }

    public function replaceCard(CheckoutCardRequest $request): JsonResponse
    {
        return $this->guardCard($request, fn () => $this->checkout->replaceCard($this->entity($request), $request->cardInput()->token, $request->idempotencyKey()));
    }

    public function contract(CheckoutContractRequest $request): JsonResponse
    {
        $call = fn () => $this->checkout->contract(
            entity: $this->entity($request),
            plan: $request->plan(),
            cycle: $request->cycle(),
            method: $request->checkoutMethod(),
            card: $request->cardInput(),
            idempotencyKey: $request->idempotencyKey(),
            returnTarget: $this->returnTarget($request),
        );

        return $request->checkoutMethod()?->isCard() && $request->cardInput() !== null
            ? $this->guardCard($request, $call)
            : response()->json(['data' => $call()]);
    }

    /**
     * Informa o CPF/CNPJ da clínica quando ela não tem documento utilizável (o `document_required` do checkout).
     * Só preenche: clínica com documento válido recusa (`document_already_set`); trocar fica com o Manager.
     * Resposta: {data: {document (canônico), formatted}}.
     */
    public function payerDocument(PayerDocumentRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->payerDocument->fill($this->entity($request), $request->document())]);
    }

    /**
     * Pacotes de créditos de IA: se a clínica pode comprar agora, formas de
     * pagamento (com o valor do pacote, se informado) e compras recentes.
     */
    public function aiCreditOptions(Request $request): JsonResponse
    {
        $validated = $request->validate(['package_code' => ['nullable', 'string', 'max:60']]);

        return response()->json(['data' => $this->aiPacks->options($this->entity($request), $validated['package_code'] ?? null)]);
    }

    /** Compra um pacote de créditos de IA pagando no checkout (Pix, boleto, cartão à vista). */
    public function aiCreditPurchase(AiCreditPackPurchaseRequest $request): JsonResponse
    {
        $call = fn () => $this->aiPacks->purchase(
            entity: $this->entity($request),
            user: $request->user(),
            packageCode: $request->packageCode(),
            method: $request->checkoutMethod(),
            card: $request->cardInput(),
            idempotencyKey: $request->idempotencyKey(),
        );

        return $request->checkoutMethod()->isCard() && $request->cardInput() !== null
            ? $this->guardCard($request, $call)
            : response()->json(['data' => $call()]);
    }

    /**
     * Descarta o pedido de pacote ainda não pago (só da própria clínica:
     * fatura de outra clínica ou que não é de pacote = 404; já pago = 409).
     * A cobrança emitida é cancelada no gateway quando possível.
     */
    public function aiCreditDiscard(Request $request, string $invoice): JsonResponse
    {
        $entity = $this->entity($request);
        $pack   = $this->aiPacks->packInvoiceOf($entity, $invoice);

        abort_if($pack === null, 404);

        return response()->json([
            'data'    => $this->aiPacks->discardByClinic($entity, $pack, $request->user()),
            'message' => __('checkout.page.ai_pack_discarded'),
        ]);
    }

    /**
     * Cartão com antifraude (CheckoutFraudGuard): antes, os limites de
     * recusas (IP, clínica, global; no cadastro, e-mail confirmado depois da
     * 1ª recusa); depois, a recusa conta.
     */
    private function guardCard(Request $request, Closure $call): JsonResponse
    {
        $entity = $this->entity($request);
        $signup = $request->routeIs('signup-checkout.*');

        $this->fraud->assertCardAllowed($request, $entity, $signup);

        try {
            return response()->json(['data' => $call()]);
        } catch (CheckoutException $e) {
            if (in_array($e->errorCode, ['card_declined', 'card_declined_generic'], true)) {
                $this->fraud->recordDecline($request, $entity, $signup);
            }

            throw $e;
        }
    }

    /**
     * Para onde o checkout hospedado devolve o cliente: o cadastro no site (rotas signup-checkout.*, que o
     * billing.contact:signup já restringe a clínica recém-criada que nunca pagou) ou o painel. Decidido pela ROTA
     * do chamador, nunca pela idade da clínica — o padrão é o painel.
     */
    private function returnTarget(Request $request): string
    {
        return $request->routeIs('signup-checkout.*') ? HostedCheckoutService::TARGET_SIGNUP : HostedCheckoutService::TARGET_PANEL;
    }

    /**
     * O gate `phone.verified` do painel mandaria este usuário confirmar o WhatsApp? Mesma regra do
     * EnsurePhoneVerified (telefone sem verificação E canal capaz de entregar o código) — apontar para a tela
     * quando o painel deixaria passar prenderia o cliente numa etapa que o sistema não exige.
     */
    private function phoneGateApplies(User $user): bool
    {
        if (blank($user->phone) || $user->phone_verified_at !== null) {
            return false;
        }

        return PhoneVerificationService::channelAvailable() && ! app(PhoneVerificationService::class)->lastDeliveryFailedPermanently($user);
    }

    private function entity(Request $request): Entity
    {
        return $request->attributes->get('checkout_entity');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\{Plan, PlanPrice, SubscriptionSetting, User};
use App\Services\Security\TurnstileVerifier;
use App\Support\Billing\PlanPricing;
use App\Support\Site\SiteContent;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class RegisteredUserController extends Controller
{
    public function create(Request $request): RedirectResponse|Response
    {
        $trialDays = SubscriptionSetting::trialDays();

        if ($trialDays <= 0) {
            return redirect()->to(route('site.home') . '#contato');
        }

        $plans = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature'), 'prices'])
            ->orderBy('sort_order')
            ->get()
            // Sem ciclo à venda (ex.: plano antigo só vitalício) não há o que contratar.
            ->filter(fn (Plan $plan) => $plan->isSellable())
            ->values()
            ->map(fn (Plan $plan) => [
                'id'                 => $plan->id,
                'name'               => $plan->name,
                'price'              => $plan->price,
                'price_period_label' => $plan->pricePeriodLabel(),
                'default_cycle'      => $plan->defaultCycle()?->value,
                'prices'             => PlanPricing::cycles($plan),
                'trial_days'         => $trialDays,
                'is_featured'        => (bool) $plan->is_featured,
                'is_free'            => (float) $plan->price === 0.0,
                'features'           => $plan->features->map(fn ($f) => [
                    'id'            => $f->id,
                    'display_label' => $f->formatForDisplay(),
                    'enabled'       => $f->feature->isBoolean() ? $f->boolValue() : true,
                ])->toArray(),
            ]);

        // Resolve against the active catalogue, without querying untrusted UUIDs.
        $requestedPlan = $request->query('plan');
        $selectedPlan  = is_string($requestedPlan)
            ? $plans->firstWhere('id', $requestedPlan)
            : null;

        // Ciclo escolhido na landing (?cycle=yearly): só vale se for um ciclo
        // vendável; se o plano não oferecer, a tela cai no ciclo padrão dele.
        $requestedCycle = $request->query('cycle');
        $selectedCycle  = is_string($requestedCycle) && in_array($requestedCycle, PlanPrice::sellableCycleValues(), true)
            ? $requestedCycle
            : null;

        return Inertia::render('Auth/Register', [
            'appName'        => config('app.name', 'EasyEye'),
            't'              => SiteContent::translations(),   // SiteLayout uses t.nav / t.footer
            'tAuth'          => trans('auth'),   // Register form uses tAuth.register.*
            'tCheckout'      => trans('checkout'), // "Contratar agora": checkout do cadastro
            'plans'          => $plans,
            'trialDays'      => $trialDays,
            'selectedPlanId' => ($selectedPlan ?? $plans->first())['id'] ?? null,
            'selectedCycle'  => $selectedCycle,
            // Captcha (Cloudflare Turnstile): só com as duas chaves configuradas.
            'turnstileSiteKey' => TurnstileVerifier::siteKey(),
            'routes'           => [
                'siteHome'     => route('site.home'),
                'go'           => route('go'),
                'login'        => route('login'),
                'register'     => route('register'),
                'contactStore' => route('contact.store'),
            ],
        ]);
    }

    /**
     * Check if an e-mail address is available (AJAX — called by the wizard).
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $exists = User::where('email', $request->string('email')->lower()->toString())->exists();

        return response()->json(['available' => ! $exists]);
    }

    /**
     * Handle the registration form submission.
     */
    public function store(RegisterRequest $request, RegisterAction $action): JsonResponse
    {
        $checkout = $request->input('start_mode') === 'checkout';

        if (! $checkout && SubscriptionSetting::trialDays() <= 0) {
            throw ValidationException::withMessages([
                'plan_id' => __('auth.register.trial_unavailable'),
            ]);
        }

        $result = $action->execute($request->validated());

        Auth::login($result['user']);

        $entityUser = $result['entityUser'];

        session([
            'selected_entity_user_id'   => $entityUser->id,
            'selected_entity_user_rule' => $entityUser->rule,
            'selected_entity_id'        => $result['entity']->id,
            'selected_entity_is_client' => $result['entity']->is_client,
            'user_rule'                 => $entityUser->rule,
        ]);

        return response()->json([
            'redirect' => route('panel.dashboard', absolute: false),
            // Contratar já pagando: o front segue para o checkout do cadastro.
            'checkout' => $checkout ? [
                'contract' => route('signup-checkout.contract', absolute: false),
                'options'  => route('signup-checkout.options', absolute: false),
                'summary'  => route('signup-checkout.summary', absolute: false),
            ] : null,
        ]);
    }
}

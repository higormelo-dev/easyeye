<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\{Plan, SubscriptionSetting, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        $plans = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id'                 => $plan->id,
                'name'               => $plan->name,
                'price'              => $plan->price,
                'price_period_label' => $plan->pricePeriodLabel(),
                'trial_days'         => $plan->trial_days,
                'is_featured'        => (bool) $plan->is_featured,
                'is_free'            => (float) $plan->price === 0.0,
                'features'           => $plan->features->map(fn ($f) => [
                    'id'            => $f->id,
                    'display_label' => $f->formatForDisplay(),
                    'enabled'       => $f->feature->isBoolean() ? $f->boolValue() : true,
                ])->toArray(),
            ]);

        return Inertia::render('Auth/Register', [
            'appName'   => config('app.name', 'EasyEye'),
            't'         => trans('site'),   // SiteLayout uses t.nav / t.footer
            'tAuth'     => trans('auth'),   // Register form uses tAuth.register.*
            'plans'     => $plans,
            'trialDays' => SubscriptionSetting::trialDays(),
            'routes'    => [
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

        return response()->json(['available' => !$exists]);
    }

    /**
     * Handle the registration form submission.
     */
    public function store(RegisterRequest $request, RegisterAction $action): JsonResponse
    {
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
        ]);
    }
}

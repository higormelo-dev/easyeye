<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\{Plan, SubscriptionSetting, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration wizard view.
     */
    public function create(): View
    {
        $plans     = Plan::active()->with('features')->orderBy('sort_order')->get();
        $trialDays = SubscriptionSetting::trialDays();

        return view('auth.register', compact('plans', 'trialDays'));
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

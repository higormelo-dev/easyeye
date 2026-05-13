<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Partner;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Partner portal redirect — before entity logic
        $partnerRecord = Partner::where('user_id', Auth::id())
            ->where('status', 'active')
            ->first();

        if ($partnerRecord) {
            session(['portal_partner_id' => $partnerRecord->id]);

            return redirect()->route('portal.dashboard');
        }

        $entityUsers = Auth::user()->entityUsers()->with('entity')
            ->where('active', true)->get();

        if (count($entityUsers) > 1) {
            return redirect()->route('selectentity.create');
        }

        if (count($entityUsers) === 1) {
            $entityUser = $entityUsers->first();

            session([
                'selected_entity_user_id'   => $entityUser->id,
                'selected_entity_user_rule' => $entityUser->rule,
                'selected_entity_id'        => $entityUser->entity->id,
                'selected_entity_is_client' => $entityUser->entity->is_client,
                'user_rule'                 => $entityUser->rule,
            ]);

            if ($entityUser->rule === 'doctor') {
                session(['selected_entity_doctor_id' => $entityUser->doctor->id]);
            }
        }

        // Usuários SaaS (non-client) não devem ser redirecionados para URLs que
        // ficaram salvas como url.intended durante uma sessão de impersonação expirada.
        if (!session('selected_entity_is_client', true)) {
            session()->forget('url.intended');

            return redirect()->route('panel.dashboard');
        }

        return redirect()->intended(route('panel.dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

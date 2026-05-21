<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Partner;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'appName' => config('app.name', 'EasyEye'),
            't'       => trans('auth'),
        ])->rootView('guest-app');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse|HttpResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Partner portal redirect — before entity logic
        $partnerRecord = Partner::where('user_id', Auth::id())
            ->where('status', 'active')
            ->first();

        if ($partnerRecord) {
            session(['portal_partner_id' => $partnerRecord->id]);

            return $this->redirectForInertia($request, route('portal.dashboard'));
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
        if (! session('selected_entity_is_client', true)) {
            session()->forget('url.intended');

            return $this->redirectForInertia($request, route('panel.dashboard'));
        }

        return redirect()->intended(route('panel.dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse|HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Inertia::location força full-page reload — necessário porque o rootView
        // muda de 'panel-app' (CSS do painel) para 'app' (CSS do site público).
        // Um redirect() simples faria o Inertia navegar via SPA, mantendo os
        // assets CSS/JS do painel no DOM e quebrando a formatação do site.
        return Inertia::location('/');
    }

    private function redirectForInertia(Request $request, string $url): RedirectResponse|HttpResponse
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->to($url);
    }
}

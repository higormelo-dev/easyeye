<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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

        $entityUsers = Auth::user()->entityUsers()->with('entity')
            ->where('active', true)->get();

        if (count($entityUsers) > 1) {
            return redirect()->intended(route('selectentity.create', absolute: false));
        }

        if (count($entityUsers) === 1) {
            $entityUser = $entityUsers->first();

            session([
                'selected_entity_user_id'   => $entityUser->id,
                'selected_entity_id'        => $entityUser->entity->id,
                'selected_entity_is_client' => $entityUser->entity->is_client,
            ]);
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

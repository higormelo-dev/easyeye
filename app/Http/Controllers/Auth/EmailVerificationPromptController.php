<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\{Inertia, Response};

class EmailVerificationPromptController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('panel.dashboard', absolute: false));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'appName' => config('app.name', 'EasyEye'),
            't'       => trans('auth'),
        ])->rootView('guest-app');
    }
}

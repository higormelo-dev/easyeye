<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal\Auth;

use App\Http\Controllers\Controller;
use App\Models\PatientAccount;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{Hash, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rules, ValidationException};
use Inertia\{Inertia, Response};

class PatientNewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('PatientPortal/Auth/ResetPassword', [
            'appName' => config('app.name', 'EasyEye'),
            'token'   => $request->route('token'),
            'email'   => $request->query('email', ''),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker('patients')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (PatientAccount $account) use ($request) {
                $account->forceFill([
                    'password'       => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($account));
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('patient-portal.login')->with('status', __($status))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
    }
}

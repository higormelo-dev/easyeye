<?php

use App\Http\Controllers\Auth\{
    AuthenticatedEntityController,
    AuthenticatedSessionController,
    ConfirmablePasswordController,
    EmailVerificationNotificationController,
    EmailVerificationPromptController,
    NewPasswordController,
    PasswordController,
    PasswordResetLinkController,
    PhoneVerificationController,
    RegisteredUserController,
    VerifyEmailController
};
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::get('register/check-email', [RegisteredUserController::class, 'checkEmail'])
        ->name('register.check-email');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('select-entity', [AuthenticatedEntityController::class, 'create'])
        ->name('selectentity.create');

    Route::post('select-entity', [AuthenticatedEntityController::class, 'store'])
        ->name('selectentity.store');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // ── Verificação de WhatsApp do responsável (código OTP via Z-API) ────────
    // Par do fluxo de e-mail acima: confirma o segundo canal de contato
    // capturado no /register. O gate `phone.verified` (grupo /panel)
    // redireciona para verify-phone até a confirmação. Reenvio 3/10min;
    // confirmação 6/min (o service ainda limita 5 erros por código).
    Route::get('verify-phone', [PhoneVerificationController::class, 'show'])
        ->name('phone.verification.notice');

    Route::post('phone/verification-code', [PhoneVerificationController::class, 'send'])
        ->middleware('throttle:3,10')
        ->name('phone.verification.send');

    Route::post('phone/verification-confirm', [PhoneVerificationController::class, 'confirm'])
        ->middleware('throttle:6,1')
        ->name('phone.verification.confirm');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

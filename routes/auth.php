<?php

use App\Http\Controllers\Auth\{
    AuthenticatedEntityController,
    AuthenticatedSessionController,
    ConfirmablePasswordController,
    DoctorInvitationResponseController,
    EmailVerificationNotificationController,
    EmailVerificationPromptController,
    NewPasswordController,
    PasswordController,
    PasswordResetLinkController,
    PhoneVerificationController,
    RegisteredUserController,
    UserInvitationResponseController,
    VerifyEmailController
};
use App\Http\Controllers\Billing\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // CSP: o cadastro pode contratar já pagando (SDK do cartão + Turnstile).
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->middleware('checkout.csp')
        ->name('register');

    Route::get('register/check-email', [RegisteredUserController::class, 'checkEmail'])
        ->name('register.check-email');

    // Limite por IP (card testing por contas em série) — AppServiceProvider 'register'.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');

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

    // Convite de clínica para médico que já tem login (link assinado do
    // e-mail). Só o próprio convidado vê/responde (controller: 404 aos demais).
    Route::middleware(['signed', 'verified'])->group(function () {
        Route::get('convites/medico/{invitation}', [DoctorInvitationResponseController::class, 'show'])
            ->name('doctor-invitations.show');
        Route::post('convites/medico/{invitation}/aceitar', [DoctorInvitationResponseController::class, 'accept'])
            ->middleware('throttle:10,1')
            ->name('doctor-invitations.accept');
        Route::post('convites/medico/{invitation}/recusar', [DoctorInvitationResponseController::class, 'decline'])
            ->middleware('throttle:10,1')
            ->name('doctor-invitations.decline');

        Route::get('convites/usuario/{invitation}', [UserInvitationResponseController::class, 'show'])
            ->name('user-invitations.show');
        Route::post('convites/usuario/{invitation}/aceitar', [UserInvitationResponseController::class, 'accept'])
            ->middleware('throttle:10,1')
            ->name('user-invitations.accept');
        Route::post('convites/usuario/{invitation}/recusar', [UserInvitationResponseController::class, 'decline'])
            ->middleware('throttle:10,1')
            ->name('user-invitations.decline');
    });

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // ── Verificação de WhatsApp do responsável (código OTP via WhatsApp oficial) ────────
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

    // Checkout do cadastro no site ("contratar já pagando", sem o trial):
    // logo após o /register, antes de confirmar e-mail/WhatsApp. Mesmas
    // proteções do checkout do painel (contato de cobrança da clínica da
    // sessão, limite de tentativas, idempotência) e só para clínica recém-
    // criada que nunca pagou (billing.contact:signup).
    Route::prefix('signup-checkout')
        ->name('signup-checkout.')
        ->middleware(['entity.selected', 'billing.contact:signup'])
        ->group(function () {
            Route::get('options', [CheckoutController::class, 'options'])->middleware('throttle:billing-checkout-read')->name('options');
            Route::get('summary', [CheckoutController::class, 'summary'])->middleware('throttle:billing-checkout-read')->name('summary');
            Route::post('contract', [CheckoutController::class, 'contract'])->middleware('throttle:billing-checkout-pay')->name('contract');
            Route::get('invoices/{invoice}/instructions', [CheckoutController::class, 'instructions'])->middleware('throttle:billing-checkout-read')->name('instructions');
            Route::post('invoices/{invoice}/charge', [CheckoutController::class, 'issueCharge'])->middleware('throttle:billing-checkout-pay')->name('charge');
            Route::post('invoices/{invoice}/card', [CheckoutController::class, 'payWithCard'])->middleware('throttle:billing-checkout-pay')->name('card');
        });
});

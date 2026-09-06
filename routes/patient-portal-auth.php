<?php

use App\Http\Controllers\PatientPortal\Auth\{
    PatientAuthenticatedSessionController,
    PatientNewPasswordController,
    PatientPasswordResetLinkController,
};
use App\Http\Controllers\PatientPortal\InvitationController;
use Illuminate\Support\Facades\Route;

// ── Portal do Paciente: autenticação ─────────────────────────────────────────
// URL base : /portal-paciente
// Guard    : "patient" — tabela e provider PRÓPRIOS (patient_accounts), NUNCA
//            o guard "web"/model User de staff (ver PatientAuthenticatedSessionController).
// Nomes    : patient-portal.*
//
// SEM rota de auto-cadastro por CPF nesta fase — a única porta de entrada é o
// convite assinado disparado pelo staff (ver InvitationController + rota
// panel.patients.portal-invitation.store em routes/web.php).
Route::prefix('portal-paciente')->name('patient-portal.')->group(function () {
    Route::get('/login', [PatientAuthenticatedSessionController::class, 'create'])->name('login');
    // Rate limit por e-mail normalizado + IP (não só IP cru) — ver
    // 'patient-login' em AppServiceProvider::boot().
    Route::post('/login', [PatientAuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:patient-login')
        ->name('login.store');

    Route::get('/esqueci-senha', [PatientPasswordResetLinkController::class, 'create'])->name('password.request');
    // Rate limit por e-mail normalizado + IP — evita enumeração de conta e
    // "email bombing" via reenvio de link de reset (ver AppServiceProvider::boot()).
    Route::post('/esqueci-senha', [PatientPasswordResetLinkController::class, 'store'])
        ->middleware('throttle:patient-password-email')
        ->name('password.email');

    Route::get('/redefinir-senha/{token}', [PatientNewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/redefinir-senha', [PatientNewPasswordController::class, 'store'])->name('password.store');

    // Aceite de convite — link assinado (temporarySignedRoute, 3 dias). GET e
    // POST compartilham a MESMA URI: o formulário reenvia a querystring
    // (person_id/expires/signature) recebida por e-mail, então o middleware
    // `signed` valida a assinatura também no POST (ver AcceptInvitation.vue).
    Route::middleware('signed')->group(function () {
        Route::get('/convite/aceitar', [InvitationController::class, 'accept'])->name('invitation.accept');
        Route::post('/convite/aceitar', [InvitationController::class, 'store'])->name('invitation.store');
    });

    Route::post('/logout', [PatientAuthenticatedSessionController::class, 'destroy'])
        ->middleware('patient.auth')
        ->name('logout');
});

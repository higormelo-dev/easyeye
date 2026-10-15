<?php

use App\Http\Controllers\ClinicalReview\{ClinicalReviewAccessCodeController, ClinicalReviewController, ClinicalReviewOpenController};
use Illuminate\Support\Facades\Route;

// ── Revisão clínica de protocolos de retorno (Fase 6, ADR 0002) ───────────────
// URL base  : /revisao-clinica
// Quem acessa: o Revisor Clínico — pessoa externa, SEM conta. O controle de acesso é o
//             link pessoal do convite (token + assinatura) e, se houver, o código de acesso.
// Middleware : clinical-review.headers em tudo (Referrer-Policy: no-referrer, no-store,
//             noindex). Deliberadamente SEM auth/entity.selected/tenant.bind: não há clínica
//             nem usuário aqui, e nada desta página toca dado de paciente.
// Nomes      : clinical-review.*
Route::prefix('revisao-clinica')->name('clinical-review.')->middleware('clinical-review.headers')->group(function () {
    // 1º salto: o ÚNICO com o token na URL. Assinado (APP_KEY) + limitado por IP. GET nunca consome o convite.
    Route::get('abrir/{invitation}/{token}', ClinicalReviewOpenController::class)
        ->middleware(['signed', 'throttle:clinical-review-open'])
        ->whereUuid('invitation')
        ->name('open');

    Route::get('indisponivel', [ClinicalReviewController::class, 'unavailable'])
        ->middleware('throttle:clinical-review-view')
        ->name('unavailable');

    // Idioma da página (sem sessão de revisão: vale também na página "indisponível").
    Route::post('idioma', [ClinicalReviewController::class, 'locale'])
        ->middleware('throttle:clinical-review-view')
        ->name('locale');

    // Código de acesso (PIN) — sessão do 1º salto, mas ainda sem exigir o PIN.
    Route::middleware(['review.session:code', 'throttle:clinical-review-view'])->group(function () {
        Route::get('codigo', [ClinicalReviewAccessCodeController::class, 'form'])->name('code');
        Route::post('codigo', [ClinicalReviewAccessCodeController::class, 'verify'])
            ->middleware('throttle:clinical-review-code')
            ->name('code.verify');
    });

    // Convite respondido: tela de conclusão com o recibo.
    Route::get('concluido', [ClinicalReviewController::class, 'done'])
        ->middleware(['review.session:done', 'throttle:clinical-review-view'])
        ->name('done');

    // A revisão em si: sessão + PIN (quando houver).
    Route::middleware(['review.session:open', 'throttle:clinical-review-view'])->group(function () {
        Route::get('/', [ClinicalReviewController::class, 'show'])->name('show');
        // Sinal de vida da página aberta: renova a sessão enquanto o Revisor lê (e o avisa cedo se ela acabou).
        Route::get('sessao', [ClinicalReviewController::class, 'keepAlive'])->name('keepalive');
        Route::put('rascunho', [ClinicalReviewController::class, 'saveDraft'])
            ->middleware('throttle:clinical-review-draft')
            ->name('draft');
        Route::post('/', [ClinicalReviewController::class, 'submit'])
            ->middleware('throttle:clinical-review-submit')
            ->name('submit');
    });
});

<?php

use App\Http\Controllers\Surgery\{PostOpDocumentsController, PostOpEvaluationsController, PostOpGuidanceController, PostOpReturnsController, SurgeryDischargeController};
use Illuminate\Support\Facades\Route;

/*
| Pós-operatório (Fase 4 / M5): retornos, avaliação estruturada, colírios e
| orientações, alta. Incluído dentro de `panel.surgery.` por routes/surgery.php.
| Papéis: o recorte fino (médico = ato clínico; secretária = retorno) está nos
| controllers (Gate PerformSurgery / ManageSurgery) — aqui só o perfil de painel.
*/
Route::middleware('entity.role:admin,doctor,secretary')
    ->prefix('{surgicalCase}/postop')
    ->whereUuid('surgicalCase')
    ->name('postop.')
    ->group(function () {
        Route::post('returns/retry', [PostOpReturnsController::class, 'retry'])->name('returns.retry');

        Route::post('evaluations', [PostOpEvaluationsController::class, 'store'])->name('evaluations.store');
        Route::put('evaluations/{evaluation}', [PostOpEvaluationsController::class, 'update'])->whereUuid('evaluation')->name('evaluations.update');
        Route::delete('evaluations/{evaluation}', [PostOpEvaluationsController::class, 'destroy'])->whereUuid('evaluation')->name('evaluations.destroy');
        Route::post('evaluations/{evaluation}/finalize', [PostOpEvaluationsController::class, 'finalize'])->whereUuid('evaluation')->name('evaluations.finalize');
        Route::post('evaluations/{evaluation}/addenda', [PostOpEvaluationsController::class, 'addendum'])->whereUuid('evaluation')->name('evaluations.addenda');

        Route::post('guidance', [PostOpGuidanceController::class, 'store'])->name('guidance.store');
        Route::get('documents/{document}/pdf', [PostOpDocumentsController::class, 'pdf'])->whereUuid('document')->name('documents.pdf');

        Route::post('discharge', [SurgeryDischargeController::class, 'store'])->name('discharge');
    });

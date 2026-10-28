<?php

use App\Http\Controllers\Surgery\SurgeryDocumentsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Documentos do caso cirúrgico (M3a) — TCLE e orientações pré-operatórias
|--------------------------------------------------------------------------
| Incluído DENTRO de panel.surgery. (routes/surgery.php). Os modelos
| (Configurações > Modelos cirúrgicos) ficam em routes/surgery/settings/.
*/
Route::middleware('entity.role:admin,doctor,secretary')
    ->prefix('{surgicalCase}/documents')
    ->whereUuid(['surgicalCase', 'document'])
    ->name('documents.')
    ->group(function () {
        Route::post('preview', [SurgeryDocumentsController::class, 'preview'])->name('preview');
        Route::post('/', [SurgeryDocumentsController::class, 'store'])->name('store');
        Route::get('{document}/pdf', [SurgeryDocumentsController::class, 'pdf'])->name('pdf');
        Route::post('{document}/signed-copy', [SurgeryDocumentsController::class, 'storeSignedCopy'])
            ->middleware('throttle:20,1')
            ->name('signed-copy.store');
        Route::get('{document}/signed-copy', [SurgeryDocumentsController::class, 'showSignedCopy'])->name('signed-copy.show');
    });

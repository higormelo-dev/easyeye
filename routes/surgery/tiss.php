<?php

use App\Http\Controllers\Surgery\SurgeryTissController;
use Illuminate\Support\Facades\Route;

/*
 * M3b — TISS do caso cirúrgico: solicitação de internação e anexo de OPME.
 * Incluído por routes/surgery.php ANTES do grupo `{surgicalCase}`. Perfis: admin, médico e
 * secretária (o pedido de autorização é deles; o financeiro fatura em lote e não abre o caso).
 */
Route::middleware('entity.role:admin,doctor,secretary')
    ->prefix('{surgicalCase}/tiss/guides')
    ->whereUuid(['surgicalCase', 'guide'])
    ->name('tiss.guides.')
    ->group(function () {
        Route::post('/', [SurgeryTissController::class, 'store'])->name('store');
        Route::put('{guide}', [SurgeryTissController::class, 'update'])->name('update');
        Route::post('{guide}/refresh', [SurgeryTissController::class, 'refresh'])->name('refresh');
        Route::post('{guide}/xml', [SurgeryTissController::class, 'generateXml'])->name('xml.generate');
        Route::get('{guide}/xml', [SurgeryTissController::class, 'downloadXml'])->middleware('throttle:30,1')->name('xml.download');
        Route::get('{guide}/pdf', [SurgeryTissController::class, 'pdf'])->middleware('throttle:30,1')->name('pdf');
        Route::post('{guide}/send', [SurgeryTissController::class, 'send'])->middleware('throttle:20,1')->name('send');
        Route::post('{guide}/reopen', [SurgeryTissController::class, 'reopen'])->name('reopen');
        Route::post('{guide}/cancel', [SurgeryTissController::class, 'cancel'])->name('cancel');
        Route::post('{guide}/response', [SurgeryTissController::class, 'response'])->name('response');
    });

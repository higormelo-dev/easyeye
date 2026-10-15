<?php

use App\Http\Controllers\Surgery\SurgeryOperationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cirurgia realizada (M4) — implantes, descrição cirúrgica e "realizada"
|--------------------------------------------------------------------------
| Incluído dentro de `panel.surgery.` (routes/surgery.php). Ato médico: o
| controller confere PerformSurgery + participação no caso; impressão é
| ManageSurgery.
*/
Route::middleware('entity.role:admin,doctor,secretary')
    ->prefix('{surgicalCase}/operation')
    ->whereUuid('surgicalCase')
    ->name('operation.')
    ->group(function () {
        Route::get('products', [SurgeryOperationController::class, 'products'])->name('products');

        Route::post('implants', [SurgeryOperationController::class, 'storeImplant'])->name('implants.store');
        Route::post('implants/{implant}/void', [SurgeryOperationController::class, 'voidImplant'])->whereUuid('implant')->name('implants.void');
        Route::post('implants/{implant}/record', [SurgeryOperationController::class, 'issueRecord'])->whereUuid('implant')->name('implants.record');
        Route::get('implants/{implant}/record/pdf', [SurgeryOperationController::class, 'recordPdf'])->whereUuid('implant')->name('implants.record-pdf');
        Route::get('implants/{implant}/labels', [SurgeryOperationController::class, 'labels'])->whereUuid('implant')->name('implants.labels');
        Route::get('card', [SurgeryOperationController::class, 'card'])->name('card');

        Route::put('description', [SurgeryOperationController::class, 'saveDescription'])->name('description.save');
        Route::post('description/finalize', [SurgeryOperationController::class, 'finalizeDescription'])->name('description.finalize');
        Route::post('description/addenda', [SurgeryOperationController::class, 'addAddendum'])->name('description.addenda');
        Route::get('description/pdf', [SurgeryOperationController::class, 'descriptionPdf'])->name('description.pdf');

        Route::post('perform', [SurgeryOperationController::class, 'perform'])->name('perform');
    });

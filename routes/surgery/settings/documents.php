<?php

use App\Http\Controllers\Setting\SurgeryTemplatesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Configurações > Modelos cirúrgicos (M3a) — TCLE, orientações pré-operatórias
| e o aviso de autorização por WhatsApp. Incluído por routes/surgery.php FORA
| do prefixo `surgery.` (nomes `panel.setting.surgery-templates.*`).
|--------------------------------------------------------------------------
*/
Route::middleware(['permission:settings.manage', 'feature:has_surgery'])
    ->prefix('setting/surgery-templates')
    ->name('setting.surgery-templates.')
    ->group(function () {
        Route::get('/', [SurgeryTemplatesController::class, 'index'])->name('index');
        Route::post('/', [SurgeryTemplatesController::class, 'store'])->name('store');
        Route::post('preview', [SurgeryTemplatesController::class, 'preview'])->name('preview');
        Route::post('adopt-defaults', [SurgeryTemplatesController::class, 'adoptDefaults'])->middleware('throttle:10,1')->name('adopt-defaults');
        Route::patch('notices', [SurgeryTemplatesController::class, 'updateNotices'])->name('notices');

        Route::prefix('{template}')->whereUuid('template')->group(function () {
            Route::get('/', [SurgeryTemplatesController::class, 'show'])->name('show');
            Route::put('/', [SurgeryTemplatesController::class, 'update'])->name('update');
            Route::delete('/', [SurgeryTemplatesController::class, 'destroy'])->name('destroy');
            Route::post('duplicate', [SurgeryTemplatesController::class, 'duplicate'])->name('duplicate');
            Route::patch('active', [SurgeryTemplatesController::class, 'setActive'])->name('active');
        });
    });

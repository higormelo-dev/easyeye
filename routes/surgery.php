<?php

use App\Http\Controllers\Setting\SurgeryFacilitiesController;
use App\Http\Controllers\Surgery\{SurgeryAuthorizationController, SurgeryCaseActionsController, SurgeryCasesController};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Módulo cirúrgico (Fase 4) — incluído DENTRO do grupo `panel.` de web.php
|--------------------------------------------------------------------------
| Prefixo /panel, nome `panel.`, middlewares do painel já aplicados (auth,
| entity.selected, tenant.bind, 2fa, check.subscription). Aqui entram só as
| travas do módulo: papel (admin, médico, secretária), recurso do plano
| `has_surgery` e, nas configurações, a permissão `settings.manage`.
|
| Cada etapa do módulo (agenda cirúrgica, checklist, documentos, TISS,
| cirurgia, pós-operatório, indicadores) registra as próprias rotas em
| routes/surgery/*.php — incluídas no fim deste arquivo.
*/

Route::middleware(['entity.role:admin,doctor,secretary,financial', 'feature:has_surgery'])
    ->prefix('surgery')
    ->name('surgery.')
    ->group(function () {
        // Rotas estáticas ANTES de `{surgicalCase}` (senão "indicators" casaria como id).
        Route::get('patients/{patient}/exams', [SurgeryCasesController::class, 'patientExams'])
            ->whereUuid('patient')
            ->name('patient-exams');

        foreach (glob(__DIR__ . '/surgery/*.php') ?: [] as $moduleRoutes) {
            require $moduleRoutes;
        }

        Route::middleware('entity.role:admin,doctor,secretary')->group(function () {
            Route::get('/', [SurgeryCasesController::class, 'index'])->name('index');
            Route::post('/', [SurgeryCasesController::class, 'store'])->name('store');

            Route::prefix('{surgicalCase}')->whereUuid('surgicalCase')->group(function () {
                Route::get('/', [SurgeryCasesController::class, 'show'])->name('show');
                Route::put('/', [SurgeryCasesController::class, 'update'])->name('update');
                Route::delete('/', [SurgeryCasesController::class, 'destroy'])->name('destroy');

                Route::post('cancel', [SurgeryCaseActionsController::class, 'cancel'])->name('cancel');
                Route::post('suspend', [SurgeryCaseActionsController::class, 'suspend'])->name('suspend');
                Route::post('resume', [SurgeryCaseActionsController::class, 'resume'])->name('resume');

                Route::post('authorization/request', [SurgeryAuthorizationController::class, 'request'])->name('authorization.request');
                Route::post('authorization/withdraw', [SurgeryAuthorizationController::class, 'withdraw'])->name('authorization.withdraw');
                Route::post('authorization/events', [SurgeryAuthorizationController::class, 'record'])->name('authorization.record');
            });
        });
    });

// Configurações > Cirurgia: locais e salas cirúrgicas.
Route::middleware(['permission:settings.manage', 'feature:has_surgery'])
    ->prefix('setting/surgery-facilities')
    ->name('setting.surgery-facilities.')
    ->group(function () {
        Route::get('/', [SurgeryFacilitiesController::class, 'index'])->name('index');
        Route::post('/', [SurgeryFacilitiesController::class, 'store'])->name('store');
        Route::put('{facility}', [SurgeryFacilitiesController::class, 'update'])->whereUuid('facility')->name('update');
        Route::delete('{facility}', [SurgeryFacilitiesController::class, 'destroy'])->whereUuid('facility')->name('destroy');
        Route::post('{facility}/rooms', [SurgeryFacilitiesController::class, 'storeRoom'])->whereUuid('facility')->name('rooms.store');
        Route::put('rooms/{room}', [SurgeryFacilitiesController::class, 'updateRoom'])->whereUuid('room')->name('rooms.update');
        Route::delete('rooms/{room}', [SurgeryFacilitiesController::class, 'destroyRoom'])->whereUuid('room')->name('rooms.destroy');
    });

// Configurações de cada etapa (fora do prefixo `surgery.`): routes/surgery/settings/*.php,
// cada arquivo registra o próprio grupo `panel.setting.<recurso>.*`.
foreach (glob(__DIR__ . '/surgery/settings/*.php') ?: [] as $settingsRoutes) {
    require $settingsRoutes;
}

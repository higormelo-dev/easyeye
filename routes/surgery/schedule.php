<?php

use App\Http\Controllers\Surgery\{SurgeryAgendaController, SurgeryChecklistController, SurgeryScheduleController};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M2 — Agenda cirúrgica e checklist de cirurgia segura
|--------------------------------------------------------------------------
| Incluído por routes/surgery.php DENTRO de `panel.surgery.` e ANTES do grupo
| `{surgicalCase}` (a agenda é estática e não pode ser lida como id).
*/

Route::middleware('entity.role:admin,doctor,secretary')->group(function () {
    Route::get('agenda', [SurgeryAgendaController::class, 'index'])->name('agenda');
    Route::get('agenda/map', [SurgeryAgendaController::class, 'map'])->name('agenda.map');

    Route::prefix('{surgicalCase}')->whereUuid('surgicalCase')->group(function () {
        Route::post('schedule', [SurgeryScheduleController::class, 'store'])->name('schedule.store');
        Route::put('schedule', [SurgeryScheduleController::class, 'update'])->name('schedule.update');
        Route::delete('schedule', [SurgeryScheduleController::class, 'destroy'])->name('schedule.destroy');
        Route::post('schedule/check', [SurgeryScheduleController::class, 'check'])->name('schedule.check');

        Route::put('checklist/{phase}', [SurgeryChecklistController::class, 'save'])
            ->whereIn('phase', ['sign_in', 'time_out', 'sign_out'])
            ->name('checklist.save');
        Route::post('checklist/{phase}/close', [SurgeryChecklistController::class, 'close'])
            ->whereIn('phase', ['sign_in', 'time_out', 'sign_out'])
            ->name('checklist.close');
    });
});

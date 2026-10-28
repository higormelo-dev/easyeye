<?php

use App\Http\Controllers\Surgery\SurgeryIndicatorsController;
use Illuminate\Support\Facades\Route;

/*
| Indicadores cirúrgicos (Fase 4 / M5). Perfil de painel aqui; o Gate
| ViewSurgeryIndicators e o recorte do médico estão no controller.
*/
Route::middleware('entity.role:admin,financial,doctor')->prefix('indicators')->group(function () {
    Route::get('/', [SurgeryIndicatorsController::class, 'index'])->name('indicators');
    Route::get('export', [SurgeryIndicatorsController::class, 'export'])->name('indicators.export');
});

<?php

use App\Http\Controllers\PatientPortal\{ClinicController, DashboardController, DocumentsController, LgpdExportController};
use Illuminate\Support\Facades\Route;

// ── Portal do Paciente: área autenticada ─────────────────────────────────────
// URL base  : /meus-documentos
// Middleware: patient.auth (guard "patient" dedicado — ver EnsurePatientAuthenticated)
// Nomes     : patient-portal.*
//
// Deliberadamente SEM tenant.bind/entity.selected: o paciente vê as N
// clínicas onde já foi atendido de uma vez só (sem "entidade ativa"). O
// EntityScope global de Patient fica inerte (TenantContext sem vínculo) e
// DashboardController::index() já filtra por person_id explicitamente.
Route::prefix('meus-documentos')->middleware('patient.auth')->name('patient-portal.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Fase 2 — grant de documento + leitura (laudo/exame/anexo liberados pelo staff).
    Route::get('/clinicas/{patient}', [ClinicController::class, 'show'])->name('clinics.show');
    Route::get('/documentos/{tipo}/{id}', [DocumentsController::class, 'view'])->name('documents.view');
    Route::get('/documentos/{tipo}/{id}/conteudo', [DocumentsController::class, 'show'])->name('documents.show');
    Route::get('/documentos/{tipo}/{id}/download', [DocumentsController::class, 'download'])->name('documents.download');

    // Fase 4 — autoatendimento LGPD (Art. 18, II/V): titular baixa os
    // próprios dados de uma clínica. throttle:6,1 (defesa em profundidade —
    // gera um LgpdRequest + lê boa parte do histórico clínico por chamada).
    Route::get('/clinicas/{patient}/exportar-dados', [LgpdExportController::class, 'export'])
        ->middleware('throttle:6,1')
        ->name('clinics.export');
});

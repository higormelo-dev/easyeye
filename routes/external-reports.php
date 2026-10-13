<?php

use App\Http\Controllers\ExternalMedicalReportController;
use Illuminate\Support\Facades\Route;

// ── Laudo de imagem pro médico solicitante (Fase 5) ──────────────────────────
// URL base  : /laudos-externos
// Middleware: signed (HMAC — único controle de acesso; sem conta/portal) +
//             throttle:external-report-view (defesa extra, ver AppServiceProvider)
// Nomes     : external-reports.*
//
// Deliberadamente SEM tenant.bind/entity.selected/auth: MedicalRecordDocumentation
// não tem entity_id nem usa BelongsToEntity (isolamento vem só por
// medical_record_id) — mesmo raciocínio de routes/patient-portal-auth.php
// pra rotas públicas sem "entidade ativa".
Route::middleware(['signed', 'throttle:external-report-view'])
    ->get('/laudos-externos/{documentation}', ExternalMedicalReportController::class)
    ->name('external-reports.show');

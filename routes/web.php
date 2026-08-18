<?php

use App\Http\Controllers\{
    AccessControl\RolesController,
    AiCreditPurchasesController,
    AiRunPromptsController,
    AiRunsController,
    ComplianceController,
    DoctorWorkScheduleController,
    DoctorsController,
    ExamDiagnosisController,
    ExternalExamImportController,
    EyeImagesController,
    Financial\BillingController as FinancialBillingController,
    Financial\CashClosingController,
    Financial\CashFlowController,
    Financial\ClinicBiController,
    Financial\FinancialReportsController,
    Financial\ProcedurePricesController,
    Financial\TissGlosasController,
    Financial\TissGuidePreValidateController,
    LocaleController,
    NoticesController,
    PatientImportsController,
    PatientsController,
    ProfileController,
    ReportsController,
    ResourceWorkScheduleController,
    ScheduleEventsController,
    SchedulesController,
    UsersController,
    WaitingListController
};
use App\Http\Controllers\{
    MedicalRecordDocumentationsController,
    MedicalRecordFilesController,
    MedicalRecordQuickActionsController,
    MedicalRecordsController,
    SiteController,
    SubscriptionExpiredController,
};
use App\Http\Controllers\{Cid10SearchController, IndicationSearchController, MedicalRecordValidationRulesController, MedicationPrescriptionFormatController, MedicineSearchController, ProcedureSearchController, ProcedureSolicitationFormatController, TonometryPdfController};
use App\Http\Controllers\Docs\ApiDocsController;
use App\Http\Controllers\{PanelDashboardController, PreferencesController};
use App\Http\Controllers\Security\TwoFactorController;
use App\Http\Controllers\Setting\{AdditionTypesController,
    ColorVisionTypesController,
    CovenantsController,
    CoverTestTypesController,
    IolLensesController,
    IrisTypesController,
    LensesController,
    NearPointConvergencesController,
    ResourcesController,
    SkinTypesController,
    SurgeryTypesController,
    TenantGatewayController,
    VisitTypesController,
    VisualAcuityTypesController};
use App\Http\Controllers\Setting\AiDoctorPromptsController;
use App\Http\Controllers\Setting\{ReportSettingsController, SecurityController};
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Route};

// Rota para trocar o idioma (sem autenticação necessária)
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// Rotas para gerenciar locale (requer autenticação)
Route::middleware(['auth'])->group(function () {
    Route::post('/locale/entity', [LocaleController::class, 'setEntityLocale'])->name('locale.entity');
    Route::delete('/locale/user', [LocaleController::class, 'clearUserLocale'])->name('locale.user.clear');
});

Route::get('/', [SiteController::class, 'index'])->name('site.home');
Route::post('/contato', [SiteController::class, 'contactStore'])->name('contact.store');

// SEO: Sitemap XML dinâmico
Route::get('/sitemap.xml', function () {
    $baseUrl = rtrim(config('app.url'), '/');
    $locales = SetLocale::SUPPORTED_LOCALES;
    $now     = now()->toW3cString();

    $urls = [
        ['loc' => $baseUrl . '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . PHP_EOL;
    $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . PHP_EOL;

    foreach ($urls as $entry) {
        $xml .= '  <url>' . PHP_EOL;
        $xml .= '    <loc>' . htmlspecialchars($entry['loc']) . '</loc>' . PHP_EOL;

        foreach ($locales as $code => $meta) {
            $hreflang = str_replace('_', '-', $code);
            $href     = $entry['loc'] . '?lang=' . $code;
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $hreflang . '" href="' . htmlspecialchars($href) . '"/>' . PHP_EOL;
        }

        $xml .= '    <lastmod>' . $now . '</lastmod>' . PHP_EOL;
        $xml .= '    <changefreq>' . $entry['changefreq'] . '</changefreq>' . PHP_EOL;
        $xml .= '    <priority>' . $entry['priority'] . '</priority>' . PHP_EOL;
        $xml .= '  </url>' . PHP_EOL;
    }

    $xml .= '</urlset>';

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');
Route::get('/go', function () {
    if (Auth::check() && session()->has('selected_entity_user_id')
        && session()->has('selected_entity_id')) {
        return redirect()->route('panel.dashboard');
    }

    if (Auth::check()) {
        if (count(Auth::user()->entityUsers) > 1) {
            return redirect()->route('selectentity.create');
        }

        return redirect()->route('panel.dashboard');
    }

    return redirect()->route('login');
})->name('go');

// Página exibida quando a assinatura está inativa/expirada
Route::get('/subscription/expired', SubscriptionExpiredController::class)
    ->middleware(['auth', 'verified', 'entity.selected'])
    ->name('subscription.expired');

Route::post('/session/ping', function () {
    return response()->json(['ok' => true]);
})->middleware(['auth'])->name('session.ping');

Route::group(
    // 2fa entra DEPOIS de entity.selected (precisa da entity na sessão para
    // decidir se exige 2FA via Entity.requires_two_factor).
    ['prefix' => 'panel', 'middleware' => ['auth', 'verified', 'entity.selected', 'tenant.bind', '2fa'], 'as' => 'panel.'],
    function () {
        Route::get('/', function () {
            return redirect()->route('panel.dashboard');
        });
        // Dashboard exclusivo para clínicas (usuários SaaS são redirecionados para /panel/manager/dashboard)
        Route::get('/dashboard', function (Request $request) {
            if (! session()->get('selected_entity_is_client')) {
                return redirect()->route('manager.dashboard');
            }

            return app(PanelDashboardController::class)($request);
        })->name('dashboard');

        // Preferências pessoais (item MELHORIA "mais humano") — endpoint único,
        // ver PreferencesController.
        Route::patch('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');

        Route::get('/eye-images', [EyeImagesController::class, 'index'])->name('eye-images.index');
        Route::get('/eye-images/search', [EyeImagesController::class, 'search'])->name('eye-images.search');
        Route::get('/eye-images/patient-urls/{patient}', [EyeImagesController::class, 'patientExamUrls'])->name('eye-images.patient-urls');
        Route::get('/eye-images/image-url/{exam}', [EyeImagesController::class, 'imageUrl'])->name('eye-images.image-url');
        // Duplicata dedicada da busca de CID-10 (mesmo Cid10SearchController) sem a
        // restrição de role de `cid10.search` (admin,doctor,secretary) — o Gerenciador
        // de Imagens não tem entity.role e não vamos afrouxar a rota usada no
        // prontuário nem restringir quem já acessa Imagens hoje.
        Route::get('/eye-images/cid10-search', Cid10SearchController::class)->name('eye-images.cid10-search');

        // Diagnóstico do exame (CID-10 + customizados da clínica). Diferente das
        // rotas de leitura acima, estas escrevem em patient_exams.diagnosis_cids
        // e criam entity_custom_diagnoses — por isso ganham entity.role aqui
        // mesmo o restante de /eye-images não tendo essa restrição. O Gate
        // EntityGate::IssueReport (exclusivo de doctor) é checado dentro do
        // controller, então admin passa pelo middleware mas ainda leva 403
        // no Gate ao tentar criar/gravar diagnóstico — só o médico assina.
        Route::get('/eye-images/diagnoses/search', [ExamDiagnosisController::class, 'search'])
            ->middleware('entity.role:admin,doctor')
            ->name('eye-images.diagnoses.search');
        Route::post('/eye-images/diagnoses', [ExamDiagnosisController::class, 'store'])
            ->middleware('entity.role:admin,doctor')
            ->name('eye-images.diagnoses.store');
        Route::put('/eye-images/exams/{exam}/diagnosis', [ExamDiagnosisController::class, 'updateDiagnosis'])
            ->middleware('entity.role:admin,doctor')
            ->name('eye-images.exams.diagnosis.update');

        // Importar exame externo (upload manual, sem integrador). entity.role
        // cobre admin/doctor/secretary; o Gate EntityGate::ImportExternalExam
        // (checado dentro do controller) replica a mesma allowlist de roles —
        // redundante de propósito, mesmo padrão do bloco de diagnóstico acima.
        Route::post('/eye-images/import', [ExternalExamImportController::class, 'store'])
            ->middleware('entity.role:admin,doctor,secretary')
            ->name('eye-images.import.store');
        // ══════════════════════════════════════════════════════════════════════
        // ACL: rotas agrupadas por nível mínimo de acesso
        // Middleware entity.role aplica a role check via EnsureEntityRole.
        // Controllers adicionam Gate::authorize() para restrições mais finas.
        // ══════════════════════════════════════════════════════════════════════

        // ── admin + secretary + doctor + financial: pacientes e médicos ───────
        // permission:patients.manage,... — RBAC granular ADITIVO (ver App\Enums\
        // Permission): quem tem a permission customizada patients.manage acessa
        // mesmo sem esses roles fixos; os roles fixos abaixo continuam com o
        // MESMO acesso que já tinham (fallback puramente aditivo, zero regressão).
        Route::middleware('permission:patients.manage,admin,financial,doctor,secretary')->group(function () {
            Route::get('doctors/cards', [DoctorsController::class, 'cards'])->name('doctors.cards');
            Route::get('doctors/{doctor}/edit-data', [DoctorsController::class, 'editData'])->name('doctors.editData');
            Route::get('doctors/{doctor}/work-schedule/data', [DoctorWorkScheduleController::class, 'data'])->name('doctors.work-schedule.data');
            Route::get('doctors/{doctor}/work-schedule', [DoctorWorkScheduleController::class, 'index'])->name('doctors.work-schedule.index');
            Route::resource('doctors', DoctorsController::class);

            Route::get('patients/cards', [PatientsController::class, 'cards'])->name('patients.cards');
            Route::get('patients/search', [PatientsController::class, 'search'])->name('patients.search');
            Route::post('patients/quick', [PatientsController::class, 'quickStore'])->name('patients.quick');
            // Importação em lote — rotas específicas antes do resource para não conflitar com {patient}
            Route::get('patients/import', [PatientImportsController::class, 'index'])->name('patients.import.index');
            Route::post('patients/import', [PatientImportsController::class, 'store'])->name('patients.import.store');
            Route::get('patients/import/template', [PatientImportsController::class, 'template'])->name('patients.import.template');
            Route::post('patients/import/{patientImport}/confirm', [PatientImportsController::class, 'confirm'])->name('patients.import.confirm');
            Route::delete('patients/import/{patientImport}/cancel', [PatientImportsController::class, 'cancel'])->name('patients.import.cancel');
            Route::get('patients/import/{patientImport}/status', [PatientImportsController::class, 'status'])->name('patients.import.status');
            Route::get('patients/import/{patientImport}/errors', [PatientImportsController::class, 'errors'])->name('patients.import.errors');
            Route::get('patients/{patient}/edit-data', [PatientsController::class, 'editData'])->name('patients.editData');
            Route::resource('patients', PatientsController::class);
        });

        // ── admin + doctor + secretary: agenda, prontuários, fila de espera ──
        Route::middleware('entity.role:admin,doctor,secretary')->group(function () {
            Route::get('patients/{patient}/tonometry-pdf', TonometryPdfController::class)
                ->name('patients.tonometry-pdf');

            Route::get('cid10/search', Cid10SearchController::class)
                ->name('cid10.search');

            // F5 — Receituário de medicamentos (autocomplete + format-line)
            Route::get('medicines/search', MedicineSearchController::class)
                ->name('medicines.search');
            Route::post('medication-prescription/format-line', MedicationPrescriptionFormatController::class)
                ->name('medication-prescription.format-line');

            // F6 — Solicitação de procedimentos (autocomplete + format-line)
            Route::get('procedures/search', ProcedureSearchController::class)
                ->name('procedures.search');
            Route::get('indications/search', IndicationSearchController::class)
                ->name('indications.search');
            Route::post('procedure-solicitation/format-line', ProcedureSolicitationFormatController::class)
                ->name('procedure-solicitation.format-line');

            Route::get(
                'patients/{patient}/medicalrecords/ajaxlist',
                [MedicalRecordsController::class, 'ajaxList'],
            )->name('patients.medicalrecords.ajaxlist');
            Route::post(
                'patients/{patient}/medicalrecords/calculate-presbyopia',
                [MedicalRecordsController::class, 'calculatePresbyopia'],
            )->name('patients.medicalrecords.calculate-presbyopia');
            Route::post(
                'medicalrecords/lens-format',
                [MedicalRecordsController::class, 'lensFormat'],
            )->name('medicalrecords.lens-format');
            // ── Prontuário: ordem IMPORTA ─────────────────────────────────────
            // ATENÇÃO: a ordem das declarações abaixo é crítica.
            //
            // O `show` usa pattern `/medicalrecords/{medicalrecord}` — se for
            // declarado ANTES de `create`, a URL `/medicalrecords/create` é
            // capturada pelo `show` tentando bindar "create" como ID do model,
            // resultando em "No query results for model MedicalRecord [create]".
            //
            // Por isso registramos as rotas write (que incluem `create`/`edit`
            // com path estático) PRIMEIRO, deixando `show`/`index` por último.

            // Escrita exclusiva para médico (CFM Res. 2.227/2018 — apenas médico
            // habilitado pode redigir, alterar e excluir prontuário clínico).
            Route::middleware('entity.role:doctor')->group(function () {
                Route::resource('patients.medicalrecords', MedicalRecordsController::class)
                    ->only(['create', 'store', 'edit', 'update', 'destroy']);
            });

            // Leitura aberta para admin/doctor/secretary — útil para admin/secretária
            // consultar histórico clínico do paciente sem poder editar.
            Route::resource('patients.medicalrecords', MedicalRecordsController::class)
                ->only(['index', 'show']);
            Route::patch(
                'patients/{patient}/medicalrecords/{medicalrecord}/restore',
                [MedicalRecordsController::class, 'restore'],
            )->name('patients.medicalrecords.restore');
            Route::get(
                'patients/{patient}/medicalrecords/{medicalrecord}/pdf',
                [MedicalRecordsController::class, 'pdf'],
            )->name('patients.medicalrecords.pdf');
            Route::get(
                'patients/{patient}/medicalrecords/{medicalrecord}/tonometry-pdf',
                [MedicalRecordsController::class, 'tonometryPdf'],
            )->name('patients.medicalrecords.tonometry-pdf');
            Route::get(
                'patients/{patient}/medicalrecords/{medicalrecord}/templates',
                [MedicalRecordsController::class, 'templates'],
            )->name('patients.medicalrecords.templates');
            Route::post(
                'patients/{patient}/medicalrecords/{medicalrecord}/template-preview',
                [MedicalRecordsController::class, 'templatePreview'],
            )->name('patients.medicalrecords.template-preview');
            Route::post(
                'patients/{patient}/medicalrecords/{medicalrecord}/quick-actions/{action}',
                [MedicalRecordQuickActionsController::class, 'issue'],
            )->name('patients.medicalrecords.quick-actions.issue');
            Route::get(
                'patients/{patient}/medicalrecords/{medicalrecord}/exam-template/{exam}',
                [MedicalRecordQuickActionsController::class, 'examTemplate'],
            )->name('patients.medicalrecords.exam-template');

            // Endpoints de IA usados pelos modais clínicos (prontuário/eye images).
            // Rate limits por (user_id + entity_id) — definidos em AppServiceProvider.
            Route::prefix('ai')->group(function () {
                // Tela única de consumo + compra de créditos + monitor de execuções.
                // URL canônica: /panel/ai/usage. Sem rota raiz /panel/ai — dashboard
                // analítico antigo descontinuado.
                Route::get('usage', [AiRunsController::class, 'index'])->name('ai-runs.index');
                Route::post('credit-purchases', [AiCreditPurchasesController::class, 'store'])
                    ->middleware('throttle:ai-store')
                    ->name('ai-credit-purchases.store');

                Route::as('ai-runs.')->group(function () {
                    Route::post('runs/estimate', [AiRunsController::class, 'estimate'])
                        ->middleware('throttle:ai-estimate')->name('estimate');
                    Route::post('runs', [AiRunsController::class, 'store'])
                        ->middleware('throttle:ai-store')->name('store');
                    Route::get('runs/{aiRun}', [AiRunsController::class, 'show'])->name('show');
                    Route::get('runs/by-patient/{patient}', [AiRunsController::class, 'listByPatient'])
                        ->middleware('throttle:ai-estimate')->name('by-patient');

                    // Autocomplete remoto do dashboard /panel/usage (Onda 3, P3)
                    Route::get('runs/search/patients', [AiRunsController::class, 'searchPatients'])
                        ->middleware('throttle:ai-estimate')->name('search.patients');
                    Route::get('runs/search/medical-records', [AiRunsController::class, 'searchMedicalRecords'])
                        ->middleware('throttle:ai-estimate')->name('search.medical-records');

                    // Prompts favoritos inline para o painel do AiAssistantPanel (Onda 3, P1)
                    Route::get('runs/my-prompts', [AiRunPromptsController::class, 'index'])
                        ->middleware('throttle:ai-estimate')->name('my-prompts.index');
                    Route::post('runs/my-prompts', [AiRunPromptsController::class, 'store'])
                        ->middleware('throttle:ai-decision')->name('my-prompts.store');
                    Route::delete('runs/my-prompts/{aiPrompt}', [AiRunPromptsController::class, 'destroy'])
                        ->middleware('throttle:ai-decision')->name('my-prompts.destroy');
                    Route::post('runs/{aiRun}/approve', [AiRunsController::class, 'approve'])
                        ->middleware('throttle:ai-decision')->name('approve');
                    Route::post('runs/{aiRun}/reject', [AiRunsController::class, 'reject'])
                        ->middleware('throttle:ai-decision')->name('reject');
                    Route::post('runs/{aiRun}/cancel', [AiRunsController::class, 'cancel'])
                        ->middleware('throttle:ai-decision')->name('cancel');
                    Route::post('runs/{aiRun}/escalate', [AiRunsController::class, 'escalate'])
                        ->middleware('throttle:ai-store')->name('escalate');
                    Route::post('runs/{aiRun}/feedback', [AiRunsController::class, 'feedback'])
                        ->middleware('throttle:ai-decision')->name('feedback');
                    Route::post('runs/{aiRun}/record', [AiRunsController::class, 'openRecordForRun'])
                        ->middleware('throttle:ai-decision')->name('record');
                });
            });

            // F7 — preview "X (extenso) dias" para atestado médico
            Route::post(
                'medical-records/day-extension-preview',
                [MedicalRecordQuickActionsController::class, 'dayExtensionPreview'],
            )->name('medical-records.day-extension-preview');

            // F9 — regras de validação client-safe do FormRequest do prontuário
            Route::get(
                'medical-records/validation-rules',
                MedicalRecordValidationRulesController::class,
            )->name('medical-records.validation-rules');

            // Documentações de prontuário
            Route::post(
                'patients/{patient}/medicalrecords/{medicalrecord}/tonometry',
                [MedicalRecordDocumentationsController::class, 'storeTonometry'],
            )->name('patients.medicalrecords.tonometry.store');
            Route::get(
                'patients/{patient}/medicalrecords/{medicalrecord}/documentations/{documentation}/pdf',
                [MedicalRecordDocumentationsController::class, 'pdf'],
            )->name('patients.medicalrecords.documentations.pdf');
            Route::resource('patients.medicalrecords.documentations', MedicalRecordDocumentationsController::class)
                ->only(['store', 'show', 'destroy']);

            // Arquivos de prontuário
            Route::resource('patients.medicalrecords.files', MedicalRecordFilesController::class)
                ->only(['store', 'show', 'destroy']);

            Route::post('schedules/ajaxlist', [SchedulesController::class, 'ajaxList'])->name('schedules.ajaxlist');
            Route::post('schedules/bulk-update', [SchedulesController::class, 'bulkUpdate'])->name('schedules.bulk-update');
            Route::post('schedules/bulk-reschedule', [SchedulesController::class, 'bulkReschedule'])->name('schedules.bulk-reschedule');
            Route::patch('schedules/{schedule}/situation', [SchedulesController::class, 'updateSituation'])->name('schedules.situation');
            Route::post('schedules/{schedule}/reschedule', [SchedulesController::class, 'reschedule'])->name('schedules.reschedule');
            Route::patch('schedules/{schedule}/mood', [SchedulesController::class, 'updateMood'])->name('schedules.mood');
            Route::post('schedules/{schedule}/cash-entry', [SchedulesController::class, 'storeCashEntry'])->name('schedules.cash-entry.store');
            Route::get('schedules/slots', [SchedulesController::class, 'slots'])->name('schedules.slots');
            Route::get('schedules/resources', [SchedulesController::class, 'resources'])->name('schedules.resources');
            Route::resource('schedules', SchedulesController::class);

            Route::patch('waiting-list/reorder', [WaitingListController::class, 'reorder'])->name('waiting-list.reorder');
            Route::get('waiting-list', [WaitingListController::class, 'index'])->name('waiting-list.index');
            Route::post('waiting-list', [WaitingListController::class, 'store'])->name('waiting-list.store');
            Route::delete('waiting-list/{waitingList}', [WaitingListController::class, 'destroy'])->name('waiting-list.destroy');

            // Compromissos não-clínicos
            Route::post('schedule-events', [ScheduleEventsController::class, 'store'])->name('schedule-events.store');
            Route::put('schedule-events/{scheduleEvent}', [ScheduleEventsController::class, 'update'])->name('schedule-events.update');
            Route::delete('schedule-events/{scheduleEvent}', [ScheduleEventsController::class, 'destroy'])->name('schedule-events.destroy');

            // Agenda de recursos (salas, equipamentos)
            Route::get('resources/{resource}/work-schedule/data', [ResourceWorkScheduleController::class, 'data'])->name('resources.work-schedule.data');
            Route::put('resources/{resource}/work-schedule', [ResourceWorkScheduleController::class, 'sync'])->name('resources.work-schedule.sync');
            Route::post('resources/{resource}/blocks', [ResourceWorkScheduleController::class, 'storeBlock'])->name('resources.blocks.store');
            Route::delete('resources/{resource}/blocks/{block}', [ResourceWorkScheduleController::class, 'destroyBlock'])->name('resources.blocks.destroy');

            // Horário de médico (write — admin pode também)
            Route::put('doctors/{doctor}/work-schedule', [DoctorWorkScheduleController::class, 'sync'])->name('doctors.work-schedule.sync');
            Route::post('doctors/{doctor}/blocks', [DoctorWorkScheduleController::class, 'storeBlock'])->name('doctors.blocks.store');
            Route::delete('doctors/{doctor}/blocks/{block}', [DoctorWorkScheduleController::class, 'destroyBlock'])->name('doctors.blocks.destroy');
        });

        // ── Mural de recados: todos os membros ────────────────────────────────
        Route::get('notices', [NoticesController::class, 'index'])->name('notices.index');
        Route::post('notices', [NoticesController::class, 'store'])->name('notices.store');
        Route::post('notices/{notice}/read', [NoticesController::class, 'markRead'])->name('notices.read');
        Route::delete('notices/{notice}', [NoticesController::class, 'destroy'])->name('notices.destroy');

        // ── admin + financial: relatórios gerenciais ──────────────────────────
        // permission:financial.manage,... — mesmo padrão do bloco de pacientes
        // acima: fallback aditivo, os roles fixos mantêm o acesso que já tinham.
        Route::middleware('permission:financial.manage,admin,financial')->group(function () {
            Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
            Route::get('reports/schedules', [ReportsController::class, 'schedules'])->name('reports.schedules');
            Route::get('reports/absenteeism', [ReportsController::class, 'absenteeism'])->name('reports.absenteeism');

            Route::prefix('financial')->as('financial.')->group(function () {
                // Dashboard Gerencial (BI)
                Route::get('bi', [ClinicBiController::class, 'index'])->name('bi.index');

                // Fluxo de caixa
                Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow.index');
                Route::post('cash-flow', [CashFlowController::class, 'store'])->name('cash-flow.store');
                Route::match(['PUT', 'PATCH'], 'cash-flow/{entry}', [CashFlowController::class, 'update'])->name('cash-flow.update');
                Route::delete('cash-flow/{entry}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

                // Tabela de preço por procedimento × convênio
                Route::get('procedure-prices', [ProcedurePricesController::class, 'index'])->name('procedure-prices.index');
                Route::post('procedure-prices', [ProcedurePricesController::class, 'store'])->name('procedure-prices.store');

                // Fechamento de caixa (lock por período)
                Route::get('cash-closing', [CashClosingController::class, 'index'])->name('cash-closing.index');
                Route::post('cash-closing', [CashClosingController::class, 'store'])->name('cash-closing.store');
                Route::delete('cash-closing/{cashClose}', [CashClosingController::class, 'destroy'])->name('cash-closing.destroy');

                // Faturamento TISS (individual e lote)
                Route::get('billing', [FinancialBillingController::class, 'index'])->name('billing.index');
                Route::post('billing/individual', [FinancialBillingController::class, 'storeIndividual'])->name('billing.individual.store');
                Route::post('billing/batch', [FinancialBillingController::class, 'storeBatch'])->name('billing.batch.store');
                Route::post('billing/batches/{batch}/submit', [FinancialBillingController::class, 'submitBatch'])->name('billing.batches.submit');
                Route::get('billing/batches/{batch}/xml', [FinancialBillingController::class, 'exportBatchXml'])->name('billing.batches.xml');
                Route::post('billing/claims/{claim}/paid', [FinancialBillingController::class, 'markClaimPaid'])->name('billing.claims.paid');
                Route::post('billing/claims/{claim}/denied', [FinancialBillingController::class, 'markClaimDenied'])->name('billing.claims.denied');

                // Pré-validação TISS (motor anti-glosa)
                Route::get('tiss/guides/{guide}/pre-validate', TissGuidePreValidateController::class)->name('tiss.guides.pre-validate');

                // Conciliação de glosas
                Route::get('tiss/glosas', [TissGlosasController::class, 'index'])->name('tiss.glosas.index');
                Route::post('tiss/glosas/{glosa}/appeal', [TissGlosasController::class, 'appeal'])->name('tiss.glosas.appeal');

                // Relatórios financeiros (exportação com drill-down)
                Route::get('reports/cash-flow', [FinancialReportsController::class, 'cashFlow'])->name('reports.cash-flow');
                Route::get('reports/covenants', [FinancialReportsController::class, 'covenants'])->name('reports.covenants');
                Route::get('reports/cash-flow/export', [FinancialReportsController::class, 'exportCashFlowCsv'])->name('reports.cash-flow.export');
                Route::get('reports/covenants/export', [FinancialReportsController::class, 'exportCovenantsCsv'])->name('reports.covenants.export');
            });
        });

        // ── Configurações pessoais do médico (não exige role admin) ──────────
        // Prompts favoritos do Assistente de IA (Onda 3, P1) — acessíveis ao
        // próprio médico autenticado. O controller faz o cross-doctor guard.
        Route::middleware('entity.role:doctor')
            ->group(function () {
                Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
                    Route::post('ai-prompts/reorder', [AiDoctorPromptsController::class, 'reorder'])
                        ->name('ai-prompts.reorder');
                    Route::resource('ai-prompts', AiDoctorPromptsController::class)
                        ->parameters(['ai-prompts' => 'aiPrompt'])
                        ->only(['index', 'store', 'update', 'destroy']);
                });
            });

        // ── admin only: compliance, controle de acesso, segurança, gateways ───
        // "Chave do cofre" do RBAC — gestão de Roles/atribuição de perfil e o
        // CRUD de usuários NUNCA são delegáveis via Permission customizada
        // (auto-escalonamento: um usuário com essas permissões poderia criar
        // uma Role com todas as permissions e se auto-atribuir). Segurança
        // (2FA) e Gateways de pagamento seguem a mesma regra — ficam
        // estritamente admin-only, sem exceção.
        Route::middleware('entity.role:admin')->group(function () {
            Route::get('reports/compliance', [ComplianceController::class, 'index'])->name('reports.compliance');
            Route::get('reports/compliance/audit', [ComplianceController::class, 'exportAuditLogs'])->name('reports.compliance.audit');
            Route::get('reports/compliance/access', [ComplianceController::class, 'exportDataAccessLogs'])->name('reports.compliance.access');

            Route::group(['prefix' => 'accesscontrol', 'as' => 'accesscontrol.'], function () {
                Route::get('users/cards', [UsersController::class, 'cards'])->name('users.cards');
                Route::resource('users', UsersController::class);
                Route::get('users/{user}/restore', [UsersController::class, 'restore'])->name('users.restore');
                Route::patch('users/{user}/roles', [UsersController::class, 'updateRoles'])->name('users.roles.update');

                // Gestão de Roles customizadas (RBAC granular ADITIVO) — CRUD
                // completo é "chave do cofre", igual accesscontrol.users acima.
                Route::resource('roles', RolesController::class)->except(['create', 'edit', 'show']);
            });

            Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
                // ── Segurança da empresa (2FA opt-in/out) ────────────────────
                Route::get('security', [SecurityController::class, 'index'])->name('security.index');
                Route::patch('security/two-factor', [SecurityController::class, 'toggleTwoFactor'])
                    ->middleware('throttle:manager-destructive') // ação rara e de alto impacto
                    ->name('security.two-factor.toggle');

                // ── Gateways de Pagamento do Tenant ────────────────────────
                // Feature `has_own_payment_gateways` libera a clínica a cadastrar
                // gateways próprios (Asaas, MP, etc.). Padrão off — a clínica usa
                // o gateway centralizado do SaaS para suas cobranças (covenants).
                Route::middleware('feature:has_own_payment_gateways')->group(function () {
                    Route::get('gateways', [TenantGatewayController::class, 'index'])->name('gateways.index');
                    Route::get('gateways/{gateway}/credentials', [TenantGatewayController::class, 'credentials'])->name('gateways.credentials');
                    Route::post('gateways/{gateway}/credentials', [TenantGatewayController::class, 'storeCredential'])->name('gateways.credentials.store');
                    Route::patch('gateways/{gateway}/credentials/{credential}/revoke', [TenantGatewayController::class, 'revokeCredential'])->name('gateways.credentials.revoke');
                });
            });
        });

        // ── piloto RBAC granular: catálogos administrativos simples ───────────
        // `permission:settings.manage` (App\Http\Middleware\EnsureEntityPermission)
        // troca o antigo `entity.role:admin` SÓ para estes 11 catálogos +
        // report-settings. hasPermissionInEntity() faz bypass de admin
        // internamente, então admin continua com acesso total; agora uma Role
        // customizada com a permission `settings.manage` também libera.
        // NÃO estender este middleware a nenhuma outra rota sem revisar a
        // regra de compliance em App\Enums\Permission — Roles customizadas
        // são uma camada ADITIVA puramente administrativa, nunca clínica.
        Route::middleware('permission:settings.manage')->group(function () {
            Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
                Route::get('covenants/cards', [CovenantsController::class, 'cards'])->name('covenants.cards');
                Route::resource('covenants', CovenantsController::class);
                Route::get('covenants/{covenant}/restore', [CovenantsController::class, 'restore'])->name('covenants.restore');

                Route::get('skintypes/cards', [SkinTypesController::class, 'cards'])->name('skintypes.cards');
                Route::resource('skintypes', SkinTypesController::class);
                Route::get('skintypes/{skintype}/restore', [SkinTypesController::class, 'restore'])->name('skintypes.restore');

                Route::get('iristypes/cards', [IrisTypesController::class, 'cards'])->name('iristypes.cards');
                Route::resource('iristypes', IrisTypesController::class);
                Route::get('iristypes/{iristype}/restore', [IrisTypesController::class, 'restore'])->name('iristypes.restore');

                Route::get('visittypes/cards', [VisitTypesController::class, 'cards'])->name('visittypes.cards');
                Route::resource('visittypes', VisitTypesController::class);
                Route::get('visittypes/{visittype}/restore', [VisitTypesController::class, 'restore'])->name('visittypes.restore');

                Route::get('additiontypes/cards', [AdditionTypesController::class, 'cards'])->name('additiontypes.cards');
                Route::resource('additiontypes', AdditionTypesController::class);
                Route::get('additiontypes/{additiontype}/restore', [AdditionTypesController::class, 'restore'])->name('additiontypes.restore');

                Route::get('surgerytypes/cards', [SurgeryTypesController::class, 'cards'])->name('surgerytypes.cards');
                Route::resource('surgerytypes', SurgeryTypesController::class);
                Route::get('surgerytypes/{surgerytype}/restore', [SurgeryTypesController::class, 'restore'])->name('surgerytypes.restore');

                Route::get('covertesttypes/cards', [CoverTestTypesController::class, 'cards'])->name('covertesttypes.cards');
                Route::resource('covertesttypes', CoverTestTypesController::class);
                Route::get('covertesttypes/{covertesttype}/restore', [CoverTestTypesController::class, 'restore'])->name('covertesttypes.restore');

                Route::get('colorvisiontypes/cards', [ColorVisionTypesController::class, 'cards'])->name('colorvisiontypes.cards');
                Route::resource('colorvisiontypes', ColorVisionTypesController::class);
                Route::get('colorvisiontypes/{colorvisiontype}/restore', [ColorVisionTypesController::class, 'restore'])->name('colorvisiontypes.restore');

                Route::get('visualacuitytypes/cards', [VisualAcuityTypesController::class, 'cards'])->name('visualacuitytypes.cards');
                Route::resource('visualacuitytypes', VisualAcuityTypesController::class);
                Route::get('visualacuitytypes/{colorvisiontype}/restore', [VisualAcuityTypesController::class, 'restore'])->name('visualacuitytypes.restore');

                Route::get('lenses/cards', [LensesController::class, 'cards'])->name('lenses.cards');
                Route::resource('lenses', LensesController::class);
                Route::get('lenses/{lens}/restore', [LensesController::class, 'restore'])->name('lenses.restore');

                Route::get('nearpointconvergences/cards', [NearPointConvergencesController::class, 'cards'])->name('nearpointconvergences.cards');
                Route::resource('nearpointconvergences', NearPointConvergencesController::class);
                Route::get('nearpointconvergences/{nearpointconvergence}/restore', [NearPointConvergencesController::class, 'restore'])->name('nearpointconvergences.restore');

                Route::get('resources/cards', [ResourcesController::class, 'cards'])->name('resources.cards');
                Route::resource('resources', ResourcesController::class);
                Route::get('resources/{resource}/restore', [ResourcesController::class, 'restore'])->name('resources.restore');

                // Modelos de documentação (receituários, atestados, etc.)
                Route::get('report-settings/cards', [ReportSettingsController::class, 'cards'])->name('report-settings.cards');
                Route::get('report-settings/{report_setting}/preview', [ReportSettingsController::class, 'preview'])->name('report-settings.preview');
                Route::post('report-settings/{report_setting}/adopt', [ReportSettingsController::class, 'adopt'])->name('report-settings.adopt');
                Route::post('report-settings/{report_setting}/reimport', [ReportSettingsController::class, 'reimport'])->name('report-settings.reimport');
                Route::resource('report-settings', ReportSettingsController::class);

                // Inventário de lentes IOL (catarata) da clínica — dado DA
                // CLÍNICA (entity_id obrigatório), diferente do catálogo
                // GLOBAL de modelos (iol_lens_models, sem escopo). `search`
                // fica ANTES do resource pra não colidir com `iollenses/{id}`.
                // `show` é mantido (fora do `except`) pro modal de detalhe/
                // edição abrir via JSON ao clicar num card — ver decisão em
                // IolLensesController::show(). `create`/`edit` ficam de fora:
                // é SPA/modal (Inertia), não há tela dedicada server-rendered.
                Route::get('iollenses/search', [IolLensesController::class, 'search'])->name('iollenses.search');
                // parameters(): binding implícito casa pelo NOME do parâmetro
                // da rota com o do método do controller — sem isso, o
                // wildcard viraria `{iollense}` (singular automático) mas os
                // métodos tipam `EntityIolLens $entityIolLens`, e o model
                // nunca seria resolvido. Mesmo ajuste já usado em ai-prompts.
                Route::resource('iollenses', IolLensesController::class)
                    ->parameters(['iollenses' => 'entityIolLens'])
                    ->except(['create', 'edit']);
            });
        });

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    },
);

// 2FA: rotas DEVEM ficar fora dos grupos protegidos por `2fa` (catch-22).
// Estão sob auth+verified — usuário precisa estar logado para configurar.
Route::middleware(['auth', 'verified'])->prefix('security/two-factor')->name('security.two-factor.')->group(function () {
    Route::get('/setup', [TwoFactorController::class, 'setup'])->name('setup');
    Route::post('/setup', [TwoFactorController::class, 'regenerateSecret'])->name('setup.store');
    Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
    Route::get('/verify', [TwoFactorController::class, 'verify'])->name('verify');
    Route::post('/verify', [TwoFactorController::class, 'verifyStore'])
        ->middleware('throttle:6,1') // 6 tentativas/min — defesa anti-brute-force
        ->name('verify.store');
});

// ─────────────────────────────────────────────────────────────────────────────
// Documentação Swagger da API de integradores — protegida por senha
// (DOCS_API_PASSWORD). Sem a senha configurada, todas as rotas respondem 404.
// Cada liberação de acesso é registrada em log (IP + user agent).
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('docs/api')->name('docs.api.')->group(function () {
    // Formulário de senha fica fora do gate (senão loop de redirect)
    Route::get('/auth', [ApiDocsController::class, 'showAuthForm'])->name('auth');
    Route::post('/auth', [ApiDocsController::class, 'authenticate'])
        ->middleware('throttle:10,1') // anti brute-force
        ->name('auth.store');

    Route::middleware('docs.access')->group(function () {
        Route::get('/', [ApiDocsController::class, 'index'])->name('index');
        Route::get('/spec', [ApiDocsController::class, 'spec'])->name('spec');
        Route::post('/logout', [ApiDocsController::class, 'logout'])->name('logout');
    });
});

require __DIR__ . '/manager.php';
require __DIR__ . '/portal.php';
require __DIR__ . '/auth.php';

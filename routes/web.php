<?php

use App\Enums\ScheduleSituation;
use App\Http\Controllers\{
    ComplianceController,
    DoctorWorkScheduleController,
    DoctorsController,
    Financial\BillingController as FinancialBillingController,
    Financial\CashFlowController,
    Financial\FinancialReportsController,
    LocaleController,
    NoticesController,
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
    SubscriptionExpiredController,
};
use App\Http\Controllers\{Cid10SearchController, TonometryPdfController};
use App\Http\Controllers\Manager\ManagerDashboardController;
use App\Http\Controllers\Setting\{AdditionTypesController,
    ColorVisionTypesController,
    CovenantsController,
    CoverTestTypesController,
    IrisTypesController,
    LensesController,
    NearPointConvergencesController,
    ResourcesController,
    SkinTypesController,
    SurgeryTypesController,
    VisitTypesController,
    VisualAcuityTypesController};
use App\Http\Controllers\Setting\ReportSettingsController;
use App\Models\{Doctor, Entity, Patient, Schedule};
use App\Services\ActivationService;
use Illuminate\Support\Facades\{Auth, Route};

// Rota para trocar o idioma (sem autenticação necessária)
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// Rotas para gerenciar locale (requer autenticação)
Route::middleware(['auth'])->group(function () {
    Route::post('/locale/entity', [LocaleController::class, 'setEntityLocale'])->name('locale.entity');
    Route::delete('/locale/user', [LocaleController::class, 'clearUserLocale'])->name('locale.user.clear');
});

Route::get('/', function () {
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
});

// Página exibida quando a assinatura está inativa/expirada
Route::get('/subscription/expired', SubscriptionExpiredController::class)
    ->middleware(['auth', 'verified', 'entity.selected'])
    ->name('subscription.expired');

Route::post('/session/ping', function () {
    return response()->json(['ok' => true]);
})->middleware(['auth'])->name('session.ping');

Route::group(
    ['prefix' => 'panel', 'middleware' => ['auth', 'verified', 'entity.selected'], 'as' => 'panel.'],
    function () {
        Route::get('/', function () {
            return redirect()->route('panel.dashboard');
        });
        Route::get('/dashboard', function () {
            if (! session()->get('selected_entity_is_client')) {
                return app()->call(ManagerDashboardController::class);
            }

            $entityId   = session('selected_entity_id');
            $today      = now()->toDateString();
            $doneValues = [
                ScheduleSituation::Attended->value,
                ScheduleSituation::NoShow->value,
                ScheduleSituation::Cancelled->value,
            ];

            $stats = [
                'entity_name'    => Entity::find($entityId)?->name ?? config('app.name'),
                'total_patients' => Patient::where('entity_id', $entityId)
                    ->where('active', true)
                    ->count(),
                'today_count' => Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->count(),
                'total_doctors' => Doctor::query()
                    ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                    ->where('entity_users.entity_id', $entityId)
                    ->where('doctors.active', true)
                    ->count(),
                'pending_today' => Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->whereNotIn('situation', $doneValues)
                    ->count(),
                'attended_today' => Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->where('situation', ScheduleSituation::Attended->value)
                    ->count(),
                'cancelled_today' => Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->whereIn('situation', [
                        ScheduleSituation::NoShow->value,
                        ScheduleSituation::Cancelled->value,
                    ])
                    ->count(),
                'recent_patients' => Patient::with('person')
                    ->where('entity_id', $entityId)
                    ->where('active', true)
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
            ];

            $activationSvc   = app(ActivationService::class);
            $activation      = $activationSvc->getProgress($entityId);
            $activationScore = $activationSvc->getScore($entityId);

            return view('system.dashboard', compact('stats', 'activation', 'activationScore'));
        })->name('dashboard');
        // ══════════════════════════════════════════════════════════════════════
        // ACL: rotas agrupadas por nível mínimo de acesso
        // Middleware entity.role aplica a role check via EnsureEntityRole.
        // Controllers adicionam Gate::authorize() para restrições mais finas.
        // ══════════════════════════════════════════════════════════════════════

        // ── admin + secretary + doctor + financial: pacientes e médicos ───────
        Route::middleware('entity.role:admin,financial,doctor,secretary')->group(function () {
            Route::get('doctors/cards', [DoctorsController::class, 'cards'])->name('doctors.cards');
            Route::get('doctors/{doctor}/edit-data', [DoctorsController::class, 'editData'])->name('doctors.editData');
            Route::get('doctors/{doctor}/work-schedule/data', [DoctorWorkScheduleController::class, 'data'])->name('doctors.work-schedule.data');
            Route::get('doctors/{doctor}/work-schedule', [DoctorWorkScheduleController::class, 'index'])->name('doctors.work-schedule.index');
            Route::resource('doctors', DoctorsController::class);

            Route::get('patients/cards', [PatientsController::class, 'cards'])->name('patients.cards');
            Route::get('patients/search', [PatientsController::class, 'search'])->name('patients.search');
            Route::post('patients/quick', [PatientsController::class, 'quickStore'])->name('patients.quick');
            Route::get('patients/{patient}/edit-data', [PatientsController::class, 'editData'])->name('patients.editData');
            Route::resource('patients', PatientsController::class);
        });

        // ── admin + doctor + secretary: agenda, prontuários, fila de espera ──
        Route::middleware('entity.role:admin,doctor,secretary')->group(function () {
            Route::get('patients/{patient}/tonometry-pdf', TonometryPdfController::class)
                ->name('patients.tonometry-pdf');

            Route::get('cid10/search', Cid10SearchController::class)
                ->name('cid10.search');

            Route::get(
                'patients/{patient}/medicalrecords/ajaxlist',
                [MedicalRecordsController::class, 'ajaxList'],
            )->name('patients.medicalrecords.ajaxlist');
            Route::post(
                'patients/{patient}/medicalrecords/calculate-presbyopia',
                [MedicalRecordsController::class, 'calculatePresbyopia'],
            )->name('patients.medicalrecords.calculate-presbyopia');
            Route::resource('patients.medicalrecords', MedicalRecordsController::class)
                ->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
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
        Route::middleware('entity.role:admin,financial')->group(function () {
            Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
            Route::get('reports/schedules', [ReportsController::class, 'schedules'])->name('reports.schedules');
            Route::get('reports/absenteeism', [ReportsController::class, 'absenteeism'])->name('reports.absenteeism');

            Route::prefix('financial')->as('financial.')->group(function () {
                // Fluxo de caixa
                Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow.index');
                Route::post('cash-flow', [CashFlowController::class, 'store'])->name('cash-flow.store');
                Route::match(['PUT', 'PATCH'], 'cash-flow/{entry}', [CashFlowController::class, 'update'])->name('cash-flow.update');
                Route::delete('cash-flow/{entry}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

                // Faturamento TISS (individual e lote)
                Route::get('billing', [FinancialBillingController::class, 'index'])->name('billing.index');
                Route::post('billing/individual', [FinancialBillingController::class, 'storeIndividual'])->name('billing.individual.store');
                Route::post('billing/batch', [FinancialBillingController::class, 'storeBatch'])->name('billing.batch.store');
                Route::post('billing/batches/{batch}/submit', [FinancialBillingController::class, 'submitBatch'])->name('billing.batches.submit');
                Route::get('billing/batches/{batch}/xml', [FinancialBillingController::class, 'exportBatchXml'])->name('billing.batches.xml');
                Route::post('billing/claims/{claim}/paid', [FinancialBillingController::class, 'markClaimPaid'])->name('billing.claims.paid');
                Route::post('billing/claims/{claim}/denied', [FinancialBillingController::class, 'markClaimDenied'])->name('billing.claims.denied');

                // Relatórios financeiros
                Route::get('reports', [FinancialReportsController::class, 'index'])->name('reports.index');
                Route::get('reports/cash-flow', [FinancialReportsController::class, 'cashFlow'])->name('reports.cash-flow');
                Route::get('reports/covenants', [FinancialReportsController::class, 'covenants'])->name('reports.covenants');
                Route::get('reports/cash-flow/export', [FinancialReportsController::class, 'exportCashFlowCsv'])->name('reports.cash-flow.export');
                Route::get('reports/covenants/export', [FinancialReportsController::class, 'exportCovenantsCsv'])->name('reports.covenants.export');
            });
        });

        // ── admin only: compliance, controle de acesso, configurações ─────────
        Route::middleware('entity.role:admin')->group(function () {
            Route::get('reports/compliance', [ComplianceController::class, 'index'])->name('reports.compliance');
            Route::get('reports/compliance/audit', [ComplianceController::class, 'exportAuditLogs'])->name('reports.compliance.audit');
            Route::get('reports/compliance/access', [ComplianceController::class, 'exportDataAccessLogs'])->name('reports.compliance.access');

            Route::group(['prefix' => 'accesscontrol', 'as' => 'accesscontrol.'], function () {
                Route::get('users/cards', [UsersController::class, 'cards'])->name('users.cards');
                Route::resource('users', UsersController::class);
                Route::get('users/{user}/restore', [UsersController::class, 'restore'])->name('users.restore');
            });

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
                Route::post('report-settings/{report_setting}/adopt', [ReportSettingsController::class, 'adopt'])->name('report-settings.adopt');
                Route::post('report-settings/{report_setting}/reimport', [ReportSettingsController::class, 'reimport'])->name('report-settings.reimport');
                Route::resource('report-settings', ReportSettingsController::class);
            });
        });

        require __DIR__ . '/manager.php';

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    },
);

require __DIR__ . '/portal.php';
require __DIR__ . '/auth.php';

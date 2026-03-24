<?php

use App\Http\Controllers\{DoctorsController,
    LocaleController,
    PatientsController,
    ProfileController,
    SchedulesController,
    UsersController};
use App\Http\Controllers\{MedicalRecordsController, SubscriptionExpiredController};
use App\Http\Controllers\Setting\{AdditionTypesController,
    ColorVisionTypesController,
    CovenantsController,
    CoverTestTypesController,
    IrisTypesController,
    LensesController,
    NearPointConvergencesController,
    SkinTypesController,
    SurgeryTypesController,
    VisitTypesController,
    VisualAcuityTypesController};
use Illuminate\Support\Facades\{Auth, Route};

// Rota para trocar o idioma (sem autenticação necessária)
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// Rotas para gerenciar locale (requer autenticação)
Route::middleware(['auth'])->group(function () {
    Route::post('/locale/entity', [LocaleController::class, 'setEntityLocale'])->name('locale.entity');
    Route::delete('/locale/user', [LocaleController::class, 'clearUserLocale'])->name('locale.user.clear');
});

Route::get('/', function () {
    if (Auth::check() && session()->has('selected_entity_user_id') &&
        session()->has('selected_entity_id')) {
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
                return view('system.manager.dashboard');
            }

            $entityId   = session('selected_entity_id');
            $today      = now()->toDateString();
            $doneValues = [
                \App\Enums\ScheduleSituation::Attended->value,
                \App\Enums\ScheduleSituation::NoShow->value,
                \App\Enums\ScheduleSituation::Cancelled->value,
            ];

            $stats = [
                'entity_name'    => \App\Models\Entity::find($entityId)?->name ?? config('app.name'),
                'total_patients' => \App\Models\Patient::where('entity_id', $entityId)
                    ->where('active', true)
                    ->count(),
                'today_count' => \App\Models\Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->count(),
                'total_doctors' => \App\Models\Doctor::query()
                    ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                    ->where('entity_users.entity_id', $entityId)
                    ->where('doctors.active', true)
                    ->count(),
                'pending_today' => \App\Models\Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->whereNotIn('situation', $doneValues)
                    ->count(),
                'attended_today' => \App\Models\Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->where('situation', \App\Enums\ScheduleSituation::Attended->value)
                    ->count(),
                'cancelled_today' => \App\Models\Schedule::where('entity_id', $entityId)
                    ->whereDate('date_time', $today)
                    ->whereIn('situation', [
                        \App\Enums\ScheduleSituation::NoShow->value,
                        \App\Enums\ScheduleSituation::Cancelled->value,
                    ])
                    ->count(),
                'recent_patients' => \App\Models\Patient::with('person')
                    ->where('entity_id', $entityId)
                    ->where('active', true)
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
            ];

            return view('system.dashboard', compact('stats'));
        })->name('dashboard');

        Route::get('doctors/cards', [DoctorsController::class, 'cards'])->name('doctors.cards');
        Route::get('doctors/{doctor}/edit-data', [DoctorsController::class, 'editData'])->name('doctors.editData');
        Route::resource('doctors', DoctorsController::class);
        Route::get('patients/cards', [PatientsController::class, 'cards'])->name('patients.cards');
        Route::get('patients/search', [PatientsController::class, 'search'])->name('patients.search');
        Route::post('patients/quick', [PatientsController::class, 'quickStore'])->name('patients.quick');
        Route::get('patients/{patient}/edit-data', [PatientsController::class, 'editData'])->name('patients.editData');
        Route::resource('patients', PatientsController::class);
        Route::get('patients/{patient}/medicalrecords/ajaxlist',
            [MedicalRecordsController::class, 'ajaxList'])->name('patients.medicalrecords.ajaxlist');
        Route::resource('patients.medicalrecords', MedicalRecordsController::class)
            ->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::post('schedules/ajaxlist', [SchedulesController::class, 'ajaxList'])->name('schedules.ajaxlist');
        Route::patch('schedules/{schedule}/situation', [SchedulesController::class, 'updateSituation'])->name('schedules.situation');
        Route::post('schedules/{schedule}/reschedule', [SchedulesController::class, 'reschedule'])->name('schedules.reschedule');
        Route::patch('schedules/{schedule}/mood', [SchedulesController::class, 'updateMood'])->name('schedules.mood');
        Route::resource('schedules', SchedulesController::class);

        Route::group(['prefix' => 'accesscontrol', 'as' => 'accesscontrol.'], function () {
            Route::get('users/cards', [UsersController::class, 'cards'])->name('users.cards');
            Route::resource('users', UsersController::class);
            Route::get('users/{user}/restore', [UsersController::class, 'restore'])
                ->name('users.restore');
        });
        Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
            Route::resource('covenants', CovenantsController::class);
            Route::get('covenants/{covenant}/restore', [CovenantsController::class, 'restore'])
                ->name('covenants.restore');
            Route::resource('skintypes', SkinTypesController::class);
            Route::get('skintypes/{skintype}/restore', [SkinTypesController::class, 'restore'])
                ->name('skintypes.restore');
            Route::resource('iristypes', IrisTypesController::class);
            Route::get('iristypes/{iristype}/restore', [IrisTypesController::class, 'restore'])
                ->name('iristypes.restore');
            Route::resource('visittypes', VisitTypesController::class);
            Route::get('visittypes/{visittype}/restore', [VisitTypesController::class, 'restore'])
                ->name('visittypes.restore');
            Route::resource('additiontypes', AdditionTypesController::class);
            Route::get('additiontypes/{additiontype}/restore', [AdditionTypesController::class, 'restore'])
                ->name('additiontypes.restore');
            Route::resource('surgerytypes', SurgeryTypesController::class);
            Route::get('surgerytypes/{surgerytype}/restore', [SurgeryTypesController::class, 'restore'])
                ->name('surgerytypes.restore');
            Route::resource('covertesttypes', CoverTestTypesController::class);
            Route::get('covertesttypes/{covertesttype}/restore', [CoverTestTypesController::class, 'restore'])
                ->name('covertesttypes.restore');
            Route::resource('colorvisiontypes', ColorVisionTypesController::class);
            Route::get('colorvisiontypes/{colorvisiontype}/restore', [ColorVisionTypesController::class, 'restore'])
                ->name('colorvisiontypes.restore');
            Route::resource('visualacuitytypes', VisualAcuityTypesController::class);
            Route::get(
                'visualacuitytypes/{colorvisiontype}/restore',
                [VisualAcuityTypesController::class, 'restore']
            )
                ->name('visualacuitytypes.restore');
            Route::resource('lenses', LensesController::class);
            Route::get('lenses/{lens}/restore', [LensesController::class, 'restore'])
                ->name('lenses.restore');
            Route::resource('nearpointconvergences', NearPointConvergencesController::class);
            Route::get('nearpointconvergences/{nearpointconvergence}/restore', [NearPointConvergencesController::class, 'restore'])
                ->name('nearpointconvergences.restore');
        });

        require __DIR__ . '/manager.php';

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    }
);

require __DIR__ . '/auth.php';

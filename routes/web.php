<?php

use App\Http\Controllers\SubscriptionExpiredController;
use App\Http\Controllers\{DoctorsController,
    LocaleController,
    PatientsController,
    ProfileController,
    SchedulesController,
    TvController,
    UsersController,
    WaitingRoomController};
use App\Http\Controllers\Setting\{AdditionTypesController,
    ColorVisionTypesController,
    CovenantsController,
    CoverTestTypesController,
    IrisTypesController,
    LensesController,
    NearPointConvergencesController,
    SkinTypesController,
    SurgeryTypesController,
    TvDisplaysController,
    VisitTypesController,
    VisualAcuityTypesController};
use Illuminate\Support\Facades\{Auth, Route};

// Rotas públicas para display de TV (sem autenticação)
Route::get('/tv', [TvController::class, 'entry'])->name('tv.entry');
Route::post('/tv/request', [TvController::class, 'requestAccess'])->name('tv.request');
Route::get('/tv/wait/{id}', [TvController::class, 'wait'])->name('tv.wait');
Route::get('/tv/status/{id}', [TvController::class, 'pollStatus'])->name('tv.status');
Route::get('/tv/{token}', [TvController::class, 'show'])->name('tv.show');
Route::get('/tv/{token}/poll', [TvController::class, 'poll'])->name('tv.poll');

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
            if (session()->get('selected_entity_is_client')) {
                return view('system.dashboard');
            }

            return view('system.manager.dashboard');
        })->name('dashboard');

        Route::get('doctors/cards', [DoctorsController::class, 'cards'])->name('doctors.cards');
        Route::get('doctors/{doctor}/edit-data', [DoctorsController::class, 'editData'])->name('doctors.editData');
        Route::resource('doctors', DoctorsController::class);
        Route::get('patients/cards', [PatientsController::class, 'cards'])->name('patients.cards');
        Route::get('patients/search', [PatientsController::class, 'search'])->name('patients.search');
        Route::post('patients/quick', [PatientsController::class, 'quickStore'])->name('patients.quick');
        Route::get('patients/{patient}/edit-data', [PatientsController::class, 'editData'])->name('patients.editData');
        Route::resource('patients', PatientsController::class);
        Route::post('schedules/ajaxlist', [SchedulesController::class, 'ajaxList'])->name('schedules.ajaxlist');
        Route::patch('schedules/{schedule}/situation', [SchedulesController::class, 'updateSituation'])->name('schedules.situation');
        Route::resource('schedules', SchedulesController::class);

        Route::get('waiting-room', [WaitingRoomController::class, 'index'])->name('waiting-room.index');
        Route::post('waiting-room/ajaxlist', [WaitingRoomController::class, 'ajaxList'])->name('waiting-room.ajaxlist');

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
            Route::prefix('tv-displays')->name('tv-displays.')->group(function () {
                Route::get('/', [TvDisplaysController::class, 'index'])->name('index');
                Route::post('/', [TvDisplaysController::class, 'store'])->name('store');
                Route::post('/{tvDisplay}/approve', [TvDisplaysController::class, 'approve'])->name('approve');
                Route::delete('/{tvDisplay}', [TvDisplaysController::class, 'destroy'])->name('destroy');
            });
        });

        require __DIR__ . '/manager.php';

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    }
);

require __DIR__ . '/auth.php';

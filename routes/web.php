<?php

use App\Http\Controllers\{AdditionTypesController,
	ColorVisionTypesController,
	CovenantsController,
	CoverTestTypesController,
	DoctorsController,
	IrisTypesController,
	LocaleController,
	Manager\EntitiesController,
	PatientsController,
	ProfileController,
	SkinTypesController,
	SurgeryTypesController,
	UsersController,
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

        Route::resource('doctors', DoctorsController::class);
        Route::resource('patients', PatientsController::class);
        Route::group(['prefix' => 'accesscontrol', 'as' => 'accesscontrol.'], function () {
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
            Route::get('visualacuitytypes/{colorvisiontype}/restore', [VisualAcuityTypesController::class, 'restore'])
                ->name('visualacuitytypes.restore');
        });

        require __DIR__ . '/manager.php';

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    }
);

require __DIR__ . '/auth.php';

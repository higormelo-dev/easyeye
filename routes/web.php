<?php

use App\Http\Controllers\{DoctorsController, Manager\EntitiesController, ProfileController, UsersController};
use Illuminate\Support\Facades\{Auth, Route};

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

        Route::group(['prefix' => 'accesscontrol', 'as' => 'accesscontrol.'], function () {
            Route::resource('users', UsersController::class);
        });
        Route::resource('doctors', DoctorsController::class);

        require __DIR__ . '/manager.php';

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::group(['prefix' => 'ajax', 'as' => 'ajax.'], function () {
            Route::group(['prefix' => 'datatables', 'as' => 'datatables'], function () {
                Route::post('/users', [UsersController::class, 'ajaxDatatable'])->name('.users');
                Route::post('/doctors', [DoctorsController::class, 'ajaxDatatable'])->name('.doctors');
                Route::post('/entities', [EntitiesController::class, 'ajaxDatatable'])->name('.entities');
                Route::post(
                    '/entity_integrators',
                    [App\Http\Controllers\Manager\EntityIntegratorsController::class, 'ajaxDatatable']
                )->name('.integrators');
            });
        });
    }
);

require __DIR__ . '/auth.php';

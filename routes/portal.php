<?php

use App\Http\Controllers\Partner\{CommissionsController, DashboardController, LeadsController};
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'portal', 'middleware' => ['auth', 'verified', 'partner'], 'as' => 'portal.'], function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/leads', [LeadsController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadsController::class, 'store'])->name('leads.store');
    Route::get('/commissions', [CommissionsController::class, 'index'])->name('commissions.index');
});

<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'active', 'auth.session'])->group(function () {
    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

    Route::middleware(['password.changed', 'branch.context'])->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('/branch/switch', BranchSwitchController::class)->name('branch.switch');
        Route::get('/team', TeamController::class)->middleware('can:view-team')->name('team.index');
        Route::get('/pos-preview', function (\Illuminate\Http\Request $request) {
            abort_unless($request->attributes->get('currentBranch'), 403, 'An active branch assignment is required.');

            return view('pos.prototype');
        })->name('pos.preview');
        Route::middleware('can:manage-business')->group(function () {
            Route::resource('users', UserController::class)->except(['show', 'destroy']);
            Route::resource('branches', BranchController::class)->except(['show', 'destroy']);
        });
    });
});

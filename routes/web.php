<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\ShiftCoverController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect(Auth::check() ? route('admin.dashboard') : route('login'));
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 帳號管理（含權限設定）
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('can:account.view')->name('index');
        Route::get('/ajax-list', [AccountController::class, 'ajaxList'])->middleware('can:account.view')->name('ajax-list');
        Route::get('/ajax-permission-map', [AccountController::class, 'ajaxPermissionMap'])->middleware('can:account.view')->name('ajax-permission-map');
        Route::post('/ajax-store', [AccountController::class, 'ajaxStore'])->middleware('can:account.create')->name('ajax-store');
        Route::put('/ajax-update/{user}', [AccountController::class, 'ajaxUpdate'])->middleware('can:account.update')->name('ajax-update');
        Route::post('/ajax-assign-permissions/{user}', [AccountController::class, 'ajaxAssignPermissions'])->middleware('can:account.assign_permission')->name('ajax-assign-permissions');
    });

    // 排班管理
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->middleware('can:shift.view')->name('index');
        Route::get('/ajax-shift-list', [ShiftController::class, 'ajaxShiftList'])->middleware('can:shift.view')->name('ajax-shift-list');
        Route::get('/ajax-cs-users', [ShiftController::class, 'ajaxCsUsers'])->middleware('can:shift.view')->name('ajax-cs-users');
        Route::put('/ajax-update-shift/{shift}', [ShiftController::class, 'ajaxUpdateShift'])->middleware('can:shift.update')->name('ajax-update-shift');
        Route::get('/ajax-assignment-list', [ShiftController::class, 'ajaxAssignmentList'])->middleware('can:shift.view')->name('ajax-assignment-list');
        Route::post('/ajax-assign', [ShiftController::class, 'ajaxAssign'])->middleware('can:shift.assign')->name('ajax-assign');
        Route::delete('/ajax-delete-assignment/{assignment}', [ShiftController::class, 'ajaxDeleteAssignment'])->middleware('can:shift.delete')->name('ajax-delete-assignment');
        Route::post('/ajax-request-swap', [ShiftController::class, 'ajaxRequestSwap'])->middleware('can:shift.swap')->name('ajax-request-swap');
        Route::put('/ajax-respond-swap/{swap}', [ShiftController::class, 'ajaxRespondSwap'])->middleware('can:shift.swap')->name('ajax-respond-swap');
        Route::get('/ajax-my-swaps', [ShiftController::class, 'ajaxMySwaps'])->middleware('can:shift.swap')->name('ajax-my-swaps');
    });

    // 代班管理
    Route::prefix('covers')->name('covers.')->group(function () {
        Route::post('/ajax-request', [ShiftCoverController::class, 'ajaxRequest'])->middleware('can:shift.cover')->name('ajax-request');
        Route::put('/ajax-respond-cover-user/{cover}', [ShiftCoverController::class, 'ajaxRespondCoverUser'])->middleware('can:shift.cover')->name('ajax-respond-cover-user');
        Route::put('/ajax-respond-admin/{cover}', [ShiftCoverController::class, 'ajaxRespondAdmin'])->middleware('can:shift.cover_review')->name('ajax-respond-admin');
        Route::get('/ajax-my-covers', [ShiftCoverController::class, 'ajaxMyCovers'])->middleware('can:shift.cover')->name('ajax-my-covers');
        Route::get('/ajax-pending', [ShiftCoverController::class, 'ajaxPendingCovers'])->middleware('can:shift.cover_review')->name('ajax-pending');
        Route::get('/ajax-approved', [ShiftCoverController::class, 'ajaxApprovedCovers'])->middleware('can:shift.view')->name('ajax-approved');
        Route::get('/ajax-all', [ShiftCoverController::class, 'ajaxAllCovers'])->middleware('can:shift.cover_review')->name('ajax-all');
    });
});

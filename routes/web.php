<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect(Auth::check() ? route('admin.accounts.index') : route('login'));
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('can:account.view')->name('index');
        Route::get('/ajax-list', [AccountController::class, 'ajaxList'])->middleware('can:account.view')->name('ajax-list');
        Route::post('/ajax-store', [AccountController::class, 'ajaxStore'])->middleware('can:account.create')->name('ajax-store');
        Route::put('/ajax-update/{user}', [AccountController::class, 'ajaxUpdate'])->middleware('can:account.update')->name('ajax-update');
        Route::delete('/ajax-delete/{user}', [AccountController::class, 'ajaxDelete'])->middleware('can:account.delete')->name('ajax-delete');
        Route::post('/ajax-assign-roles/{user}', [AccountController::class, 'ajaxAssignRoles'])->middleware('can:account.assign_role')->name('ajax-assign-roles');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('can:role.view')->name('index');
        Route::get('/ajax-list', [RoleController::class, 'ajaxList'])->middleware('can:role.view')->name('ajax-list');
        Route::get('/ajax-permission-map', [RoleController::class, 'ajaxPermissionMap'])->middleware('can:role.view')->name('ajax-permission-map');
        Route::post('/ajax-store', [RoleController::class, 'ajaxStore'])->middleware('can:role.create')->name('ajax-store');
        Route::put('/ajax-update/{role}', [RoleController::class, 'ajaxUpdate'])->middleware('can:role.update')->name('ajax-update');
        Route::delete('/ajax-delete/{role}', [RoleController::class, 'ajaxDelete'])->middleware('can:role.delete')->name('ajax-delete');
        Route::post('/ajax-assign-permissions/{role}', [RoleController::class, 'ajaxAssignPermissions'])->middleware('can:role.assign_permission')->name('ajax-assign-permissions');
    });
});

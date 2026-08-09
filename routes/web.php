<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\StationController;
use App\Http\Controllers\Admin\TelegramBroadcastController;
use App\Http\Controllers\Admin\TelegramChatController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\ShiftCoverController;
use App\Http\Controllers\Admin\PushController;
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

    // 個人資訊修改
    Route::put('/profile', [AccountController::class, 'ajaxUpdateProfile'])->name('profile.update');

    // 帳號管理（含權限設定）
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('can:account.view')->name('index');
        Route::get('/ajax-list', [AccountController::class, 'ajaxList'])->middleware('can:account.view')->name('ajax-list');
        Route::get('/ajax-permission-map', [AccountController::class, 'ajaxPermissionMap'])->middleware('can:account.view')->name('ajax-permission-map');
        Route::post('/ajax-store', [AccountController::class, 'ajaxStore'])->middleware('can:account.create')->name('ajax-store');
        Route::put('/ajax-update/{user}', [AccountController::class, 'ajaxUpdate'])->middleware('can:account.update')->name('ajax-update');
        Route::post('/ajax-assign-permissions/{user}', [AccountController::class, 'ajaxAssignPermissions'])->middleware('can:account.assign_permission')->name('ajax-assign-permissions');
        Route::get('/permissions/{user}', [AccountController::class, 'permissionsPage'])->middleware('can:account.assign_permission')->name('permissions');
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

    // 打卡出勤
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('index');
        Route::post('/ajax-clock-in', [AttendanceController::class, 'ajaxClockIn'])->middleware('can:attendance.clock')->name('ajax-clock-in');
        Route::post('/ajax-clock-out', [AttendanceController::class, 'ajaxClockOut'])->middleware('can:attendance.clock')->name('ajax-clock-out');
        Route::get('/ajax-today-status', [AttendanceController::class, 'ajaxTodayStatus'])->middleware('can:attendance.view')->name('ajax-today-status');
        Route::get('/ajax-my-monthly', [AttendanceController::class, 'ajaxMyMonthly'])->middleware('can:attendance.view')->name('ajax-my-monthly');
        Route::get('/ajax-monthly-report', [AttendanceController::class, 'ajaxMonthlyReport'])->middleware('can:attendance.report')->name('ajax-monthly-report');
        Route::get('/detail/{userId}', [AttendanceController::class, 'detail'])->middleware('can:attendance.report')->name('detail');
        Route::get('/ajax-user-monthly', [AttendanceController::class, 'ajaxUserMonthly'])->middleware('can:attendance.report')->name('ajax-user-monthly');
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

    // Telegram 客服聊天（所有登入者可查看，回覆需權限，值班自動從排班指派）
    Route::prefix('telegram-chat')->name('telegram-chat.')->group(function () {
        Route::get('/', [TelegramChatController::class, 'index'])->name('index');
        Route::get('/ajax-groups', [TelegramChatController::class, 'ajaxGroups'])->name('ajax-groups');
        Route::get('/ajax-messages', [TelegramChatController::class, 'ajaxMessages'])->name('ajax-messages');
        Route::post('/ajax-reply', [TelegramChatController::class, 'ajaxReply'])->middleware('can:telegram_chat.reply')->name('ajax-reply');
        Route::post('/ajax-send-image', [TelegramChatController::class, 'ajaxSendImage'])->middleware('can:telegram_chat.reply')->name('ajax-send-image');
        Route::post('/ajax-react', [TelegramChatController::class, 'ajaxReact'])->middleware('can:telegram_chat.reply')->name('ajax-react');
    });

    // 站台管理
    Route::prefix('stations')->name('stations.')->group(function () {
        Route::get('/', [StationController::class, 'index'])->middleware('can:station.view')->name('index');
        Route::get('/ajax-list', [StationController::class, 'ajaxList'])->middleware('can:station.view')->name('ajax-list');
        Route::post('/ajax-store', [StationController::class, 'ajaxStore'])->middleware('can:station.create')->name('ajax-store');
        Route::put('/ajax-update/{station}', [StationController::class, 'ajaxUpdate'])->middleware('can:station.update')->name('ajax-update');
        Route::post('/ajax-sync-credits/{station}', [StationController::class, 'ajaxSyncCredits'])->middleware('can:station.update')->name('ajax-sync-credits');
        Route::get('/ajax-systems', [StationController::class, 'ajaxSystems'])->middleware('can:station.view')->name('ajax-systems');
        Route::post('/ajax-store-system', [StationController::class, 'ajaxStoreSystem'])->middleware('can:station.create')->name('ajax-store-system');
        Route::get('/ajax-bot-groups', [StationController::class, 'ajaxBotGroups'])->middleware('can:station.update')->name('ajax-bot-groups');
    });

    // Telegram 群發公告
    Route::prefix('telegram-broadcast')->name('telegram-broadcast.')->group(function () {
        Route::get('/', [TelegramBroadcastController::class, 'index'])->middleware('can:telegram_chat.broadcast')->name('index');
        Route::get('/ajax-groups', [TelegramBroadcastController::class, 'ajaxGroups'])->middleware('can:telegram_chat.broadcast')->name('ajax-groups');
        Route::post('/ajax-send', [TelegramBroadcastController::class, 'ajaxSend'])->middleware('can:telegram_chat.broadcast')->name('ajax-send');
        Route::get('/ajax-history', [TelegramBroadcastController::class, 'ajaxHistory'])->middleware('can:telegram_chat.broadcast')->name('ajax-history');
    });

    // Web Push 訂閱
    Route::prefix('push')->name('push.')->group(function () {
        Route::post('/ajax-subscribe', [PushController::class, 'ajaxSubscribe'])->name('ajax-subscribe');
        Route::post('/ajax-unsubscribe', [PushController::class, 'ajaxUnsubscribe'])->name('ajax-unsubscribe');
    });
});

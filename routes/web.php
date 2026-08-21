<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginLogController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\StationController;
use App\Http\Controllers\Admin\TelegramBroadcastController;
use App\Http\Controllers\Admin\TelegramChatController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\ShiftCoverController;
use App\Http\Controllers\Admin\PushController;
use App\Http\Controllers\Admin\VmController;
use App\Http\Controllers\Admin\PaymentConfigController;
use App\Http\Controllers\Admin\LeaveRequestController;
use App\Http\Controllers\Admin\StaffManageController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\TaskBoardController;
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
    Route::get('/dashboard/ajax-usdt-rate', [DashboardController::class, 'ajaxUsdtRate'])->name('dashboard.ajax-usdt-rate');

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
        Route::get('/ajax-login-log/{user}', [AccountController::class, 'ajaxLoginLog'])->middleware('can:account.view')->name('ajax-login-log');
    });

    // 排班管理
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::get('/ajax-shift-list', [ShiftController::class, 'ajaxShiftList'])->middleware('can:shift.view')->name('ajax-shift-list');
        Route::get('/ajax-cs-users', [ShiftController::class, 'ajaxCsUsers'])->middleware('can:shift.view')->name('ajax-cs-users');
        Route::post('/ajax-store-shift', [ShiftController::class, 'ajaxStoreShift'])->middleware('can:shift.update')->name('ajax-store-shift');
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
        Route::post('/ajax-request-amend', [AttendanceController::class, 'ajaxRequestAmend'])->middleware('can:attendance.amend')->name('ajax-request-amend');
        Route::get('/ajax-my-amendments', [AttendanceController::class, 'ajaxMyAmendments'])->middleware('can:attendance.amend')->name('ajax-my-amendments');
        Route::get('/ajax-amendments', [AttendanceController::class, 'ajaxAmendments'])->middleware('can:attendance.amend_review')->name('ajax-amendments');
        Route::put('/ajax-respond-amend/{amendment}', [AttendanceController::class, 'ajaxRespondAmend'])->middleware('can:attendance.amend_review')->name('ajax-respond-amend');
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

    // 請假管理
    Route::prefix('leave-request')->name('leave-request.')->group(function () {
        Route::get('/ajax-my-list', [LeaveRequestController::class, 'ajaxMyList'])->middleware('can:leave_request.apply')->name('ajax-my-list');
        Route::get('/ajax-list', [LeaveRequestController::class, 'ajaxList'])->middleware('can:leave_request.review')->name('ajax-list');
        Route::post('/ajax-store', [LeaveRequestController::class, 'ajaxStore'])->middleware('can:leave_request.apply')->name('ajax-store');
        Route::put('/ajax-respond/{leaveRequest}', [LeaveRequestController::class, 'ajaxRespond'])->middleware('can:leave_request.review')->name('ajax-respond');
        Route::get('/ajax-check', [LeaveRequestController::class, 'ajaxCheck'])->middleware('can:shift.view')->name('ajax-check');
    });

    // Telegram 客服聊天（所有登入者可查看，回覆需權限，值班自動從排班指派）
    Route::prefix('telegram-chat')->name('telegram-chat.')->group(function () {
        Route::get('/', [TelegramChatController::class, 'index'])->name('index');
        Route::get('/ajax-groups', [TelegramChatController::class, 'ajaxGroups'])->name('ajax-groups');
        Route::get('/ajax-messages', [TelegramChatController::class, 'ajaxMessages'])->name('ajax-messages');
        Route::post('/ajax-reply', [TelegramChatController::class, 'ajaxReply'])->middleware('can:telegram_chat.reply')->name('ajax-reply');
        Route::post('/ajax-send-image', [TelegramChatController::class, 'ajaxSendImage'])->middleware('can:telegram_chat.reply')->name('ajax-send-image');
        Route::post('/ajax-react', [TelegramChatController::class, 'ajaxReact'])->middleware('can:telegram_chat.reply')->name('ajax-react');
        Route::post('/ajax-typing', [TelegramChatController::class, 'ajaxTyping'])->middleware('can:telegram_chat.reply')->name('ajax-typing');
    });

    // 站台管理
    Route::prefix('stations')->name('stations.')->group(function () {
        Route::get('/', [StationController::class, 'index'])->name('index');
        Route::get('/ajax-list', [StationController::class, 'ajaxList'])->middleware('can:station.view')->name('ajax-list');
        Route::post('/ajax-store', [StationController::class, 'ajaxStore'])->middleware('can:station.create')->name('ajax-store');
        Route::put('/ajax-update/{station}', [StationController::class, 'ajaxUpdate'])->middleware('can:station.update')->name('ajax-update');
        Route::post('/ajax-sync-credits/{station}', [StationController::class, 'ajaxSyncCredits'])->middleware('can:station.update')->name('ajax-sync-credits');
        Route::get('/ajax-systems', [StationController::class, 'ajaxSystems'])->middleware('can:station.view')->name('ajax-systems');
        Route::post('/ajax-store-system', [StationController::class, 'ajaxStoreSystem'])->middleware('can:station.create')->name('ajax-store-system');
        Route::get('/ajax-bot-groups', [StationController::class, 'ajaxBotGroups'])->middleware('can:station.update')->name('ajax-bot-groups');
        Route::put('/ajax-update-system/{system}', [StationController::class, 'ajaxUpdateSystem'])->middleware('can:station.create')->name('ajax-update-system');
        Route::get('/ajax-topup-list', [StationController::class, 'ajaxTopupList'])->middleware('can:station.topup_view')->name('ajax-topup-list');
        Route::post('/ajax-topup-store', [StationController::class, 'ajaxTopupStore'])->middleware('can:station.topup_apply')->name('ajax-topup-store');
        Route::put('/ajax-topup-approve/{topup}', [StationController::class, 'ajaxTopupApprove'])->middleware('can:station.topup_approve')->name('ajax-topup-approve');
        Route::put('/ajax-topup-reject/{topup}', [StationController::class, 'ajaxTopupReject'])->middleware('can:station.topup_approve')->name('ajax-topup-reject');
    });

    // Telegram 群發公告
    Route::prefix('telegram-broadcast')->name('telegram-broadcast.')->group(function () {
        Route::get('/', [TelegramBroadcastController::class, 'index'])->middleware('can:telegram_chat.broadcast')->name('index');
        Route::get('/ajax-groups', [TelegramBroadcastController::class, 'ajaxGroups'])->middleware('can:telegram_chat.broadcast')->name('ajax-groups');
        Route::post('/ajax-send', [TelegramBroadcastController::class, 'ajaxSend'])->middleware('can:telegram_chat.broadcast')->name('ajax-send');
        Route::get('/ajax-history', [TelegramBroadcastController::class, 'ajaxHistory'])->middleware('can:telegram_chat.broadcast')->name('ajax-history');
    });

    // 虛擬機管理
    Route::prefix('vm')->name('vm.')->group(function () {
        Route::get('/', [VmController::class, 'index'])->name('index');
        Route::get('/ajax-list', [VmController::class, 'ajaxList'])->middleware('can:vm.view')->name('ajax-list');
        Route::post('/ajax-store', [VmController::class, 'ajaxStore'])->middleware('can:vm.create')->name('ajax-store');
        Route::put('/ajax-update/{vm}', [VmController::class, 'ajaxUpdate'])->middleware('can:vm.update')->name('ajax-update');
        Route::post('/ajax-toggle-power/{vm}', [VmController::class, 'ajaxTogglePower'])->middleware('can:vm.update')->name('ajax-toggle-power');
        Route::get('/ajax-billing', [VmController::class, 'ajaxBillingList'])->middleware('can:vm.billing_view')->name('ajax-billing');
        Route::post('/ajax-upload-proof/{billing}', [VmController::class, 'ajaxUploadProof'])->middleware('can:vm.billing_upload')->name('ajax-upload-proof');
        Route::put('/ajax-approve-paid/{billing}', [VmController::class, 'ajaxApprovePaid'])->middleware('can:vm.billing_approve')->name('ajax-approve-paid');
        Route::put('/ajax-mark-paid/{billing}', [VmController::class, 'ajaxMarkPaid'])->middleware('can:vm.billing_approve')->name('ajax-mark-paid');
        Route::post('/ajax-generate-billing', [VmController::class, 'ajaxGenerateBilling'])->middleware('can:vm.billing_approve')->name('ajax-generate-billing');
        Route::post('/ajax-send-payment-notice', [VmController::class, 'ajaxSendPaymentNotice'])->middleware('can:vm.billing_view')->name('ajax-send-payment-notice');
    });

    // 繳款設定
    Route::prefix('payment-config')->name('payment-config.')->group(function () {
        Route::get('/', [PaymentConfigController::class, 'index'])->middleware('can:payment_config.view')->name('index');
        Route::get('/ajax-list', [PaymentConfigController::class, 'ajaxList'])->middleware('can:payment_config.view')->name('ajax-list');
        Route::post('/ajax-store', [PaymentConfigController::class, 'ajaxStore'])->middleware('can:payment_config.manage')->name('ajax-store');
        Route::post('/ajax-update/{config}', [PaymentConfigController::class, 'ajaxUpdate'])->middleware('can:payment_config.manage')->name('ajax-update');
        Route::delete('/ajax-delete/{config}', [PaymentConfigController::class, 'ajaxDelete'])->middleware('can:payment_config.manage')->name('ajax-delete');
        Route::get('/ajax-by-system', [PaymentConfigController::class, 'ajaxBySystem'])->middleware('can:payment_config.view')->name('ajax-by-system');
        Route::post('/ajax-render-template', [PaymentConfigController::class, 'ajaxRenderTemplate'])->middleware('can:payment_config.view')->name('ajax-render-template');
    });

    // 任務看板
    Route::prefix('task-board')->name('task-board.')->group(function () {
        Route::get('/', [TaskBoardController::class, 'index'])->middleware('can:task_board.view')->name('index');
        Route::get('/ajax-board', [TaskBoardController::class, 'ajaxBoard'])->middleware('can:task_board.view')->name('ajax-board');
        Route::get('/ajax-task/{task}', [TaskBoardController::class, 'ajaxTaskDetail'])->middleware('can:task_board.view')->name('ajax-task-detail');
        Route::post('/ajax-store-task', [TaskBoardController::class, 'ajaxStoreTask'])->middleware('can:task_board.create')->name('ajax-store-task');
        Route::put('/ajax-update-task/{task}', [TaskBoardController::class, 'ajaxUpdateTask'])->middleware('can:task_board.update')->name('ajax-update-task');
        Route::put('/ajax-archive-task/{task}', [TaskBoardController::class, 'ajaxArchiveTask'])->middleware('can:task_board.delete')->name('ajax-archive-task');
        Route::get('/ajax-archived-list', [TaskBoardController::class, 'ajaxArchivedList'])->middleware('can:task_board.view')->name('ajax-archived-list');
        Route::put('/ajax-restore-task/{task}', [TaskBoardController::class, 'ajaxRestoreTask'])->middleware('can:task_board.delete')->name('ajax-restore-task');
        Route::put('/ajax-move-task/{task}', [TaskBoardController::class, 'ajaxMoveTask'])->middleware('can:task_board.update')->name('ajax-move-task');
        Route::post('/ajax-reorder', [TaskBoardController::class, 'ajaxReorder'])->middleware('can:task_board.update')->name('ajax-reorder');
        Route::get('/ajax-projects', [TaskBoardController::class, 'ajaxProjects'])->middleware('can:task_board.view')->name('ajax-projects');
        Route::get('/ajax-assignees', [TaskBoardController::class, 'ajaxAssignees'])->middleware('can:task_board.view')->name('ajax-assignees');
        Route::get('/ajax-comments/{task}', [TaskBoardController::class, 'ajaxComments'])->middleware('can:task_board.view')->name('ajax-comments');
        Route::post('/ajax-store-comment/{task}', [TaskBoardController::class, 'ajaxStoreComment'])->middleware('can:task_board.view')->name('ajax-store-comment');
        Route::delete('/ajax-delete-comment/{comment}', [TaskBoardController::class, 'ajaxDeleteComment'])->middleware('can:task_board.delete_comment')->name('ajax-delete-comment');
        Route::post('/ajax-upload-editor-image', [TaskBoardController::class, 'ajaxUploadEditorImage'])->middleware('can:task_board.create')->name('ajax-upload-editor-image');
        Route::get('/ajax-activities/{task}', [TaskBoardController::class, 'ajaxActivities'])->middleware('can:task_board.view')->name('ajax-activities');
    });

    // 內部管理
    Route::prefix('staff-manage')->name('staff-manage.')->group(function () {
        Route::get('/', [StaffManageController::class, 'index'])->middleware('can:staff_manage.view')->name('index');
        Route::get('/ajax-list', [StaffManageController::class, 'ajaxList'])->middleware('can:staff_manage.view')->name('ajax-list');
        Route::put('/ajax-update/{user}', [StaffManageController::class, 'ajaxUpdate'])->middleware('can:staff_manage.edit')->name('ajax-update');
    });

    // 專案管理
    Route::prefix('project')->name('project.')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->middleware('can:project.view')->name('index');
        Route::get('/ajax-list', [ProjectController::class, 'ajaxList'])->middleware('can:project.view')->name('ajax-list');
        Route::post('/ajax-store', [ProjectController::class, 'ajaxStore'])->middleware('can:project.edit')->name('ajax-store');
        Route::put('/ajax-update/{project}', [ProjectController::class, 'ajaxUpdate'])->middleware('can:project.edit')->name('ajax-update');
    });

    // 財務管理
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('/', [FinanceController::class, 'index'])->middleware('can:finance.view')->name('index');
        Route::get('/ajax-detail', [FinanceController::class, 'ajaxDetail'])->middleware('can:finance.view')->name('ajax-detail');
        Route::post('/ajax-store-expense', [FinanceController::class, 'ajaxStoreExpense'])->middleware('can:finance.edit')->name('ajax-store-expense');
        Route::put('/ajax-update-summary/{record}', [FinanceController::class, 'ajaxUpdateSummary'])->middleware('can:finance.edit')->name('ajax-update-summary');
        Route::delete('/ajax-delete-expense/{expense}', [FinanceController::class, 'ajaxDeleteExpense'])->middleware('can:finance.edit')->name('ajax-delete-expense');
    });

    // 登入紀錄
    Route::prefix('login-log')->name('login-log.')->group(function () {
        Route::get('/', [LoginLogController::class, 'index'])->middleware('can:login_log.view')->name('index');
        Route::get('/ajax-list', [LoginLogController::class, 'ajaxList'])->middleware('can:login_log.view')->name('ajax-list');
        Route::get('/ajax-my-log', [LoginLogController::class, 'ajaxMyLog'])->name('ajax-my-log');
    });

    // Web Push 訂閱
    Route::prefix('push')->name('push.')->group(function () {
        Route::post('/ajax-subscribe', [PushController::class, 'ajaxSubscribe'])->name('ajax-subscribe');
        Route::post('/ajax-unsubscribe', [PushController::class, 'ajaxUnsubscribe'])->name('ajax-unsubscribe');
    });
});

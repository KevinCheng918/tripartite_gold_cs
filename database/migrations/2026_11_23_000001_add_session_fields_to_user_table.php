<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 帳號加上「閒置多久自動登出」與「被踢出的時間點」
 *
 * 需求方 2026-11-22：「個人設定這邊想要有一個自選登出的間隔時間……
 * 也可以選完全不登出，然後後台的帳號管理要有一個按鈕功能是踢出登入。」
 *
 * ⚠⚠ **`SESSION_LIFETIME=120` 其實沒在作用**：`common.js` 每 15 分鐘
 * 無條件 ping 一次續期，所以只要分頁開著 session 就一直被往後推 ——
 * 「完全不登出」就是現況，這次真正要新增的是「閒置 N 分鐘就登出」。
 *
 * 詳見 .claude/knowledge/features/session-timeout-and-kick.md
 */
class AddSessionFieldsToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('user')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            if (!Schema::hasColumn('user', 'session_timeout')) {
                /*
                 * 閒置幾分鐘自動登出。**0 = 不登出**（預設值，等於現況）。
                 *
                 * ⚠ 預設給 0 而不是某個時數：這是「新增的選項」，不該在
                 * 沒有人選擇的情況下改變既有行為 —— 上線當天全員突然
                 * 被登出會是一場意外。
                 */
                $table->unsignedSmallInteger('session_timeout')
                    ->default(0)
                    ->after('show_in_shift')
                    ->comment('閒置幾分鐘自動登出；0=不登出');
            }

            if (!Schema::hasColumn('user', 'force_logout_at')) {
                /*
                 * 管理者按下「踢出登入」的時間。
                 *
                 * ⚠ **不是旗標而是時間點**：middleware 比的是
                 * 「這個時間 > 本次登入時間嗎」—— 被踢的人重新登入之後
                 * `login_at` 會比它新，自然就過得去，**不需要有人去清這一欄**。
                 */
                $table->dateTime('force_logout_at')
                    ->nullable()
                    ->after('session_timeout')
                    ->comment('這個時間之前的登入一律失效（踢出登入）');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('user')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            foreach (['session_timeout', 'force_logout_at'] as $column) {
                if (Schema::hasColumn('user', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}

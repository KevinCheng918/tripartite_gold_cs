<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立使用者表（user）
 *
 * 對齊主系統 tripartite_gold 的命名慣例，
 * 使用 account 作為登入帳號欄位，密碼以 Crypt::encrypt 加密。
 */
class CreateUsersTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('user')) {
            return;
        }

        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->string('account', 100)->unique()->comment('登入帳號');
            $table->string('nickname', 100)->comment('顯示暱稱');
            $table->string('password')->comment('密碼（Crypt::encrypt 加密）');
            $table->tinyInteger('status')->default(1)->comment('狀態：1=啟用, 0=停用');
            $table->timestamps();
            $table->softDeletes();
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

        Schema::dropIfExists('user');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLoginLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('login_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('登入帳號 ID（失敗時可能無對應帳號）');
            $table->string('account', 50)->comment('輸入的帳號名稱');
            $table->string('ip', 45)->comment('登入 IP');
            $table->boolean('is_success')->default(false)->comment('登入是否成功');
            $table->string('device', 255)->nullable()->comment('登入裝置（User-Agent）');
            $table->string('fail_reason', 100)->nullable()->comment('失敗原因');
            $table->timestamp('created_at')->useCurrent()->comment('登入時間');

            $table->index('user_id');
            $table->index('account');
            $table->index('is_success');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('login_log');
    }
}

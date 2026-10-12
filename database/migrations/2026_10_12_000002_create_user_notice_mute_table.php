<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立「這個人關掉了哪些私訊」表（user_notice_mute）
 *
 * **有列 ＝ 關掉，沒列 ＝ 要收。** 用存在與否表達狀態，比照
 * `telegram_group_staff_allow`，不再多一個「有列但 enabled=1」這種
 * 跟「沒列」意思相同的狀態。
 *
 * ⚠ **方向刻意是黑名單**（記「不要收的」而不是「要收的」）：
 *
 *   - 上線當天不必為既有帳號補任何資料，所有人維持現狀照收
 *   - 以後新增第五種通知時，所有人自動收得到，不必再補一輪
 *
 * 做成白名單的話，上線那一刻每個人都會變成「沒勾選」而收不到任何通知。
 *
 * ⚠ 只管**個人版**私訊（需求方 2026-10-12）。主管在通知設定頁被勾進
 * 「完整版」名單的那一份照發 —— 那是職責，不是個人喜好。
 */
class CreateUserNoticeMuteTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('user_notice_mute')) {
            return;
        }

        Schema::create('user_notice_mute', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete：帳號沒了，他的靜音設定留著只會在 id 被重用時誤傷新帳號
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()
                ->comment('哪個同仁');

            /*
             * 對應 constants.NOTICE.PERSONAL 的值。
             *
             * 用字串而不是數字：這張表會被人直接查（「為什麼他沒收到班表」），
             * 看到 `shift` 比看到 `1` 省一次翻 config 的功夫。
             */
            $table->string('notice_key', 32)->comment('哪一種通知');

            $table->timestamps();

            $table->unique(['user_id', 'notice_key'], 'user_notice_mute_user_key_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('user_notice_mute')) {
            return;
        }

        Schema::dropIfExists('user_notice_mute');
    }
}

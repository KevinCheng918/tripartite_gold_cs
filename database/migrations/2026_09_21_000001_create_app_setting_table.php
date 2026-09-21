<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立全域設定表（app_setting）
 *
 * key-value 形式的系統層級設定，由後台「全域設定」頁維護。
 * 自動回覆的 Claude 憑證、內部支援群組、對客話術模板都存在這裡 ——
 * 這些是要能隨時修改的東西，放 .env 就得改檔案重新部署。
 *
 * is_secret 的欄位（token、API key）以 Crypt::encrypt 加密後存入，
 * 對外一律只回遮罩，明文不會離開伺服器。
 */
class CreateAppSettingTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('app_setting')) {
            return;
        }

        Schema::create('app_setting', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique()->comment('設定鍵，如 auto_reply.claude_token');
            $table->text('value')->nullable()->comment('設定值，is_secret=1 時為 Crypt::encrypt 加密後的內容');
            $table->boolean('is_secret')->default(false)->comment('是否為敏感值（加密存放、對外只回遮罩）');
            $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete()->comment('最後修改者');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('app_setting')) {
            return;
        }

        Schema::dropIfExists('app_setting');
    }
}

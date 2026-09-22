<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 題庫加上來源標記
 *
 * 給 QuickReplyKnowledgeSeeder 用：判斷這題是不是已經建過了。
 *
 * 用 import_key 而不是比對問題文字 —— 客服會改題目與答案的語氣
 * （題庫答案是原文送給客戶的），比對文字會在改過之後又插一筆重複的進去。
 */
class AddImportKeyToQuickReplyItemTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('quick_reply_item') || Schema::hasColumn('quick_reply_item', 'import_key')) {
            return;
        }

        Schema::table('quick_reply_item', function (Blueprint $table) {
            $table->string('import_key', 100)->nullable()->after('answer')
                ->comment('seeder 建立的題目來源識別，人工新增的為 null');

            $table->unique('import_key', 'quick_reply_item_import_key_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('quick_reply_item') || !Schema::hasColumn('quick_reply_item', 'import_key')) {
            return;
        }

        Schema::table('quick_reply_item', function (Blueprint $table) {
            $table->dropUnique('quick_reply_item_import_key_unique');
            $table->dropColumn('import_key');
        });
    }
}

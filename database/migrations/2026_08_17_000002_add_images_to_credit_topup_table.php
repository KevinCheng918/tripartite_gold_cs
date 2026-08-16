<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddImagesToCreditTopupTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('credit_topup') && !Schema::hasColumn('credit_topup', 'images')) {
            Schema::table('credit_topup', function (Blueprint $table) {
                $table->json('images')->nullable()->after('note')->comment('上傳圖片路徑陣列');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('credit_topup') && Schema::hasColumn('credit_topup', 'images')) {
            Schema::table('credit_topup', function (Blueprint $table) {
                $table->dropColumn('images');
            });
        }
    }
}

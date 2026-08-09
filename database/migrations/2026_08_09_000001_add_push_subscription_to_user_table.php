<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user 表加入 Web Push 訂閱欄位
 */
class AddPushSubscriptionToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('user')) {
            return;
        }

        if (Schema::hasColumn('user', 'push_endpoint')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->text('push_endpoint')->nullable()->comment('Web Push endpoint URL');
            $table->string('push_p256dh_key', 255)->nullable()->comment('Web Push p256dh key');
            $table->string('push_auth_token', 255)->nullable()->comment('Web Push auth token');
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
            if (Schema::hasColumn('user', 'push_endpoint')) {
                $table->dropColumn('push_endpoint');
            }
            if (Schema::hasColumn('user', 'push_p256dh_key')) {
                $table->dropColumn('push_p256dh_key');
            }
            if (Schema::hasColumn('user', 'push_auth_token')) {
                $table->dropColumn('push_auth_token');
            }
        });
    }
}

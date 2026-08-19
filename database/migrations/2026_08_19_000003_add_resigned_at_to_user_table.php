<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResignedAtToUserTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user') && !Schema::hasColumn('user', 'resigned_at')) {
            Schema::table('user', function (Blueprint $table) {
                $table->date('resigned_at')->nullable()->after('hired_at')->comment('離職日');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('user') && Schema::hasColumn('user', 'resigned_at')) {
            Schema::table('user', function (Blueprint $table) {
                $table->dropColumn('resigned_at');
            });
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHiredAtAndEquipmentsToUserTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('user')) {
            if (!Schema::hasColumn('user', 'hired_at')) {
                Schema::table('user', function (Blueprint $table) {
                    $table->date('hired_at')->nullable()->after('project_ids')->comment('到職日');
                });
            }
            if (!Schema::hasColumn('user', 'resigned_at')) {
                Schema::table('user', function (Blueprint $table) {
                    $table->date('resigned_at')->nullable()->after('hired_at')->comment('離職日');
                });
            }
            if (!Schema::hasColumn('user', 'equipments')) {
                Schema::table('user', function (Blueprint $table) {
                    $table->json('equipments')->nullable()->after('resigned_at')->comment('設備清單 JSON');
                });
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('user')) {
            if (Schema::hasColumn('user', 'hired_at')) {
                Schema::table('user', function (Blueprint $table) {
                    $table->dropColumn('hired_at');
                });
            }
            if (Schema::hasColumn('user', 'equipments')) {
                Schema::table('user', function (Blueprint $table) {
                    $table->dropColumn('equipments');
                });
            }
        }
    }
}

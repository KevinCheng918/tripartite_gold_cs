<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPoweredOffAtToVmServerTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('vm_server', 'powered_off_at')) {
            Schema::table('vm_server', function (Blueprint $table) {
                $table->date('powered_off_at')->nullable()->after('power_status')->comment('關機日期');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('vm_server', 'powered_off_at')) {
            Schema::table('vm_server', function (Blueprint $table) {
                $table->dropColumn('powered_off_at');
            });
        }
    }
}

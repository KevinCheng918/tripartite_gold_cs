<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRolePermissionsTable extends Migration
{
    public function up()
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_keyword', 100);
            $table->timestamps();
            $table->unique(['role_id', 'permission_keyword']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('role_permissions');
    }
}

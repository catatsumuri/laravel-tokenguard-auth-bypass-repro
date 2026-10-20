<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApiTokenToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 64)->nullable()->unique();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['api_token']);
            $table->dropColumn('api_token');
        });
    }
}

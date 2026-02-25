<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyPickupsTable extends Migration
{
    public function up()
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->unsignedBigInteger('account_carrier_id')->nullable()->change();
            $table->unsignedBigInteger('collector_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->unsignedBigInteger('account_carrier_id')->nullable(false)->change();
            $table->unsignedBigInteger('collector_id')->nullable(false)->change();
        });
    }
}

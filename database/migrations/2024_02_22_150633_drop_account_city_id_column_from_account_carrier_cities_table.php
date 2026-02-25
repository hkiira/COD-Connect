<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('accounts_carriers_cities', function (Blueprint $table) {
            $table->dropForeign(['account_city_id']);
            $table->dropColumn('account_city_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('accounts_carriers_cities', function (Blueprint $table) {
            $table->unsignedBigInteger('account_city_id')->nullable();
            $table->foreign('account_city_id')->references('id')->on('account_city');
        });
    }
};

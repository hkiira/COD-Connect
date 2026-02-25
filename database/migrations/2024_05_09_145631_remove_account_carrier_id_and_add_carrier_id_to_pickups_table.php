<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveAccountCarrierIdAndAddCarrierIdToPickupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign(['account_carrier_id']);
            $table->dropColumn('account_carrier_id');
            $table->unsignedBigInteger('carrier_id')->nullable();
            $table->foreign('carrier_id')->references('id')->on('carriers');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign(['carrier_id']);
            $table->dropColumn('carrier_id');
            $table->unsignedBigInteger('account_carrier_id')->nullable();
            $table->foreign('account_carrier_id')->references('id')->on('account_carriers');
        });
    }
}
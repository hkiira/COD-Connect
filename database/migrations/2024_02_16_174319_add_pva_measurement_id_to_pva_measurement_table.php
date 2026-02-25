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
        Schema::table('pva_measurement', function (Blueprint $table) {
            $table->unsignedBigInteger('pva_measurement_id')->nullable();
            $table->foreign('pva_measurement_id')->references('id')->on('pva_measurement');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pva_measurement', function (Blueprint $table) {
            $table->dropForeign(['pva_measurement_id']);
            $table->dropColumn('pva_measurement_id');
        });
    }
};

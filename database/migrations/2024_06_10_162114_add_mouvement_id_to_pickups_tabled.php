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
        Schema::table('pickups', function (Blueprint $table) {
            $table->unsignedBigInteger('mouvement_id')->nullable();
            $table->foreign('mouvement_id')->references('id')->on('mouvements');
        });
    }

    public function down()
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign(['mouvement_id']);
            $table->dropColumn('mouvement_id');
        });
    }
};

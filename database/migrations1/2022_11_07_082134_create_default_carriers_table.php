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
        Schema::create('default_carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained();
            $table->foreignId('city_id')->constrained();
            $table->string('name',50);
            $table->integer('price')->length(11)->default(0);
            $table->integer('return')->length(11)->default(0);
            $table->integer('delivery_time')->length(11)->default(1);
            $table->integer('statut')->length(11)->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('default_carriers');
    }
};

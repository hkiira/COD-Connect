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
        Schema::create('offerable_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offerable_variation_id')->nullable();
            $table->foreign('offerable_variation_id')->references('id')->on('offerable_variations');

            $table->unsignedBigInteger('offerable_id');
            $table->foreign('offerable_id')->references('id')->on('offerables');

            $table->unsignedBigInteger('account_id');
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offerable_variations');
    }
};

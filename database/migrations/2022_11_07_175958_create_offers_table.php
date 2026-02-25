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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->bigInteger('offer_id')->unsigned()->nullable();
            $table->foreign('offer_id')->references('id')->on('offers');
            $table->double('price',8,2)->nullable();
            $table->double('shipping_price',8,2)->nullable();
            $table->integer('statut')->length(11)->default(1)->nullable();
            $table->foreignId('account_id')->nullable()->constrained();
            $table->foreignId('brand_id')->nullable()->constrained();
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
        Schema::dropIfExists('offers');
    }
};

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
        Schema::create('pva_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('product_variation_attribute_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('account_id')->references('id')->on('accounts');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pva_packs');
    }
};

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
        Schema::create('mouvement_pva', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mouvement_id');
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->integer('quantite');
            $table->decimal('price', 10, 2);
            $table->unsignedBigInteger('account_user_id');
            $table->tinyInteger('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('mouvement_id')->references('id')->on('mouvements');
            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('account_user_id')->references('id')->on('account_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mouvement_product_variation');
    }
};

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
        Schema::create('taxonomy_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_product_id');
            $table->unsignedBigInteger('taxonomy_id');
            $table->integer('statut')->length(2)->default(1);
            $table->foreign('account_product_id')->references('id')->on('account_product');
            $table->foreign('taxonomy_id')->references('id')->on('taxonomies');
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
        Schema::dropIfExists('taxonomy_product');
    }
};

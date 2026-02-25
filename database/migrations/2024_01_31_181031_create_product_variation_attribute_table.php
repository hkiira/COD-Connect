<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * a supprimer product_variationAttribute
     * @return void
     */
    public function up()
    {
        Schema::create('product_variation_attribute', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_attribute_id');
            $table->unsignedBigInteger('account_id');
            $table->integer('statut')->default(1);
            $table->timestamps();

            // Add foreign key constraints
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('variation_attribute_id')->references('id')->on('variation_attributes');
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
        Schema::dropIfExists('product_variation_attribute');
    }
};

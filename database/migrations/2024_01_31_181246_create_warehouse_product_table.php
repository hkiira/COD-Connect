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
        Schema::create('warehouse_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('statut')->length(2)->default(1);
            $table->integer('quantity')->default(0);
            $table->timestamps();

            // Clés étrangères
            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('warehouse_product');
    }
};

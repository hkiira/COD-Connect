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
        Schema::create('product_supplier', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('price', 10, 2); // Assuming 'price' is a decimal column
            $table->tinyInteger('statut')->default(1); // Assuming 'statut' is a tiny integer column with default value 0
            $table->timestamps();

            // Define foreign key constraints
            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
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
        Schema::dropIfExists('product_supplier');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

public function up()
    {
        Schema::create('order_products', function (Blueprint $table) {
            $table->id();

            // Assuming 'account_user_id' references 'id' in 'account_user' table
            $table->unsignedBigInteger('account_user_id');
            $table->foreign('account_user_id')->references('id')->on('account_user');

            // Assuming 'order_id' references 'id' in an 'orders' table
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders');

            // Assuming 'product_variationattribute_id' references 'id' in 'product_variationAttribute' table
            $table->unsignedBigInteger('product_variationattribute_id');
            $table->foreign('product_variationattribute_id')
                  ->references('id')
                  ->on('product_variationAttribute');

            // Assuming 'offer_id' references 'id' in an 'offers' table
            $table->unsignedBigInteger('offer_id');
            $table->foreign('offer_id')->references('id')->on('offers');

            $table->float('price', 8, 2);
            $table->integer('quantity')->default(1);
            $table->string('statut')->default('1');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_products');
    }
};

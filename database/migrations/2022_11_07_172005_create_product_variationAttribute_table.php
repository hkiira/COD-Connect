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
Schema::create('product_variationAttribute', function (Blueprint $table) {
    $table->id();

    // Assuming 'product_id' references 'id' in 'products' table
    $table->unsignedBigInteger('product_id');
    $table->foreign('product_id')->references('id')->on('products');

    // Assuming 'variationAttribute_id' references 'id' in 'variationAttributes' table
    $table->unsignedBigInteger('variationAttribute_id');
    $table->foreign('variationAttribute_id')->references('id')->on('variationAttributes');

    $table->unsignedBigInteger('account_user_id');
    $table->foreign('account_user_id')->references('id')->on('account_user');

    $table->integer('statut')->default(1);
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
        Schema::dropIfExists('product_variationAttributes');
    }
};

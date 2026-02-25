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
        Schema::create('supplier_order_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_order_id');
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->unsignedBigInteger('supplier_receipt_id')->nullable();
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->unsignedBigInteger('account_user_id');
            $table->tinyInteger('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Define foreign key constraints
            $table->foreign('supplier_order_id')->references('id')->on('supplier_orders');
            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('supplier_receipt_id')->references('id')->on('supplier_receipts');
            // Assuming 'users' is the name of the users table
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
        Schema::dropIfExists('supplier_order_product');
    }
};

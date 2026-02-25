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
        Schema::create('supplier_order_product_variationAttribute', function (Blueprint $table) {
            $table->id();

            // Shortening the foreign key constraint name
            $table->unsignedBigInteger('supplier_order_id')->nullable();
            $table->foreign('supplier_order_id', 'supplier_order_fk')
                  ->references('id')
                  ->on('supplier_orders');

            $table->unsignedBigInteger('product_variationattribute_id');
            $table->foreign('product_variationattribute_id', 'product_var_attr_fk')
                  ->references('id')
                  ->on('product_variationAttribute');

            // Shortening the foreign key constraint name
            $table->unsignedBigInteger('supplier_receipt_id')->nullable();
            $table->foreign('supplier_receipt_id', 'supplier_receipt_fk')
                  ->references('id')
                  ->on('supplier_receipts');

            $table->integer('quantity')->default(0);
            $table->integer('price')->default(0);

            // Shortening the foreign key constraint name
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'user_fk')->references('id')->on('users');

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
        Schema::dropIfExists('supplier_order_products');
    }
};

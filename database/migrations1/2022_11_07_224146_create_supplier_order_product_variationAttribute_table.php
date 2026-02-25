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
            $table->bigInteger('supplier_order_id')->unsigned()->nullable();
            $table->foreign('supplier_order_id', 'supplier_order_id_fk')
            ->references('id')
            ->on('supplier_orders');

            $table->bigInteger('product_variationattribute_id')->unsigned(); //->nullable();
            $table->foreign('product_variationattribute_id', 'product_variationAttribute_id_fk')
            ->references('id')
            ->on('product_variationattribute');
            $table->bigInteger('supplier_receipt_id')->unsigned()->nullable();
            $table->foreign('supplier_receipt_id', 'supplier_receipt_id_fk')
            ->references('id')
            ->on('supplier_receipts');
            $table->integer('quantity')->length(11)->default(0);
            $table->integer('price')->length(11)->default(0);
            $table->foreignId('user_id')->constrained();
            $table->integer('statut')->length(11)->default(1);
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

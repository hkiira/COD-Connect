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
        Schema::table('product_supplier', function (Blueprint $table) {
            $table->renameColumn('product_id', 'product_variation_attribute_id');

            // Add a foreign key constraint
            $table->foreign('product_variation_attribute_id')
                ->references('id')->on('product_variation_attribute'); // You can modify the onDelete behavior as needed
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_supplier', function (Blueprint $table) {
            $table->renameColumn('product_variation_attribute_id', 'product_id');
            $table->dropForeign(['product_variation_attribute_id']);
        
        });
    }
};

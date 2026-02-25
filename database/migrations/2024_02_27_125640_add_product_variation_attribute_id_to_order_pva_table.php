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
        Schema::table('order_pva', function (Blueprint $table) {
            $table->foreignId('product_variation_attribute_id')->constrained('product_variation_attribute');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->dropForeign(['product_variation_attribute_id']);
            $table->dropColumn('product_variation_attribute_id');
        });
    }
};

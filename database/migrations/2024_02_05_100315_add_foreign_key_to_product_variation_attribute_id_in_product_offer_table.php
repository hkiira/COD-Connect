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
        Schema::table('product_offer', function (Blueprint $table) {
            $table->foreign('product_variation_attribute_id')
                ->references('id')->on('product_variation_attribute');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_offer', function (Blueprint $table) {
            $table->dropForeign(['product_variation_attribute_id']);
        });
    }
};

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
            // Replace 'foreign_key_name' with the actual name of the foreign key constraint
            $table->dropForeign(['account_product_id']);
        });
        Schema::table('product_offer', function (Blueprint $table) {
            $table->dropColumn('account_product_id');
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
            //
        });
    }
};

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
            $table->dropForeign('order_products_offer_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            $table->dropColumn('offer_id');
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
            $table->unsignedBigInteger('offer_id');
            $table->foreign('offer_id')->references('id')->on('offers')->name('order_products_offer_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
        });
    }
};

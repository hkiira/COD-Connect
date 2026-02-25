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
        Schema::table('paymentables', function (Blueprint $table) {
            $table->unsignedBigInteger('compensationable_id');

            // Définir la clé étrangère
            $table->foreign('compensationable_id')->references('id')->on('compensationables');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('paymentables', function (Blueprint $table) {
            // Supprimer la clé étrangère
            $table->dropForeign(['compensationable_id']);

            // Supprimer la colonne
            $table->dropColumn('compensationable_id');
        });
    }
};

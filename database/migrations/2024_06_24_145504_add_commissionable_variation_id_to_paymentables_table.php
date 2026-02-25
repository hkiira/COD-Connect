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
            $table->unsignedBigInteger('commissionable_variation_id')->nullable(false);

            // Définir la clé étrangère
            $table->foreign('commissionable_variation_id')->references('id')->on('commissionable_variations')->onDelete('cascade');
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
            $table->dropForeign(['commissionable_variation_id']);

            // Supprimer la colonne
            $table->dropColumn('commissionable_variation_id');
        });
    }
};

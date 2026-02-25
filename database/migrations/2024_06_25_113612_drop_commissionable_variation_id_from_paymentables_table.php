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
            // Supprimer la contrainte de clé étrangère
            $table->dropForeign(['commissionable_variation_id']);

            // Supprimer la colonne
            $table->dropColumn('commissionable_variation_id');
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
            $table->unsignedBigInteger('commissionable_variation_id')->nullable(false);

            // Ajouter de nouveau la contrainte de clé étrangère
            $table->foreign('commissionable_variation_id')->references('id')->on('commissionable_variations')->onDelete('cascade');
        });
    }
};

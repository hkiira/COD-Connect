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
            $table->dropForeign(['commissionable_id']);
            
            // Supprimer la colonne
            $table->dropColumn('commissionable_id');
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
            // Ajouter la colonne de retour
            $table->unsignedBigInteger('commissionable_id');

            // Définir la clé étrangère de retour
            $table->foreign('commissionable_id')->references('id')->on('commissionables')->onDelete('cascade');
        });
    }
};

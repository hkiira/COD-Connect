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
        Schema::table('commission_types', function (Blueprint $table) {
            $table->unsignedBigInteger('commission_category_id')->default(1);

            // Définir la clé étrangère
            $table->foreign('commission_category_id')->references('id')->on('commission_categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('commission_types', function (Blueprint $table) {
            // Supprimer la clé étrangère
            $table->dropForeign(['commission_category_id']);

            // Supprimer la colonne
            $table->dropColumn('commission_category_id');
        });
    }
};

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
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('account_user_id')->nullable(false);

            // Définir la clé étrangère
            $table->foreign('account_user_id')->references('id')->on('account_user')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            // Supprimer la clé étrangère
            $table->dropForeign(['account_user_id']);

            // Supprimer la colonne
            $table->dropColumn('account_user_id');
        });
    }
};

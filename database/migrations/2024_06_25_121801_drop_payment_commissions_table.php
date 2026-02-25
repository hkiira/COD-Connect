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
        Schema::dropIfExists('payment_commissions');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Optionnellement, vous pouvez recréer la table ici si nécessaire
        Schema::create('payment_commissions', function (Blueprint $table) {
            $table->id();
            // Ajoutez ici les autres colonnes que vous aviez dans cette table
            $table->timestamps();
        });
    }
};

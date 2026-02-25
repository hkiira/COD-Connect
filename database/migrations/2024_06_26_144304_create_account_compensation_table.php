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
        Schema::create('account_compensation', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('compensation_id');
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Définir les clés étrangères
            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('compensation_id')->references('id')->on('compensations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('account_compensation');
    }
};

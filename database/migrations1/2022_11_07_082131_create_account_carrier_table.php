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
        Schema::create('account_carrier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->integer('autocode')->length(11); 
            $table->integer('statut')->length(11)->nullable();
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('account_carrier');
    }
};

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
        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('statut')->length(2)->default(1);
            $table->timestamps();

            // Clés étrangères
            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('warehouse_user');
    }
};

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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->unsignedBigInteger('warehouse_nature_id');
            $table->unsignedBigInteger('warehouse_type_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->integer('statut')->length(2)->default(1);
            $table->timestamps();

            // Clés étrangères
            $table->foreign('warehouse_nature_id')->references('id')->on('warehouse_natures');
            $table->foreign('warehouse_type_id')->references('id')->on('warehouse_types');
            $table->foreign('account_id')->references('id')->on('accounts');
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
        Schema::dropIfExists('warehouses');
    }
};

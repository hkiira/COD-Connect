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
        Schema::create('mouvements', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('warehoused')->nullable();
            $table->unsignedBigInteger('warehouse_nature_id')->nullable();
            $table->unsignedBigInteger('warehouse_natured')->nullable();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('mouvement_type_id');
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('warehoused')->references('id')->on('warehouses');
            $table->foreign('warehouse_nature_id')->references('id')->on('warehouse_natures');
            $table->foreign('warehouse_natured')->references('id')->on('warehouse_natures');
            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('mouvement_type_id')->references('id')->on('mouvement_types');
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mouvements');
    }
};

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
        Schema::create('inventory_pva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_attribute_id')->constrained('product_variation_attribute');
            $table->foreignId('inventory_id')->constrained('inventories');
            $table->integer('quantity');
            $table->timestamps();
            $table->softDeletes();
            $table->integer('statut')->default(1);
            $table->foreignId('account_user_id')->constrained('account_user');
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_pva');
    }
};

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
        Schema::create('variationAttributes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('account_user_id')->unsigned()->nullable();
            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->bigInteger('variationAttribute_id')->unsigned()->nullable();
            $table->foreign('variationAttribute_id')->references('id')->on('VariationAttributes');
            $table->foreignId('attribute_id')->nullable()->constrained();
            $table->integer('statut')->default(1);
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
        Schema::dropIfExists('VariationAttributes');
    }
};

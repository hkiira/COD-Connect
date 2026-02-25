<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * a supprimer la table variationAttributes
     * @return void
     */
    public function up()
    {
        Schema::create('variation_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('variation_attribute_id');
            $table->unsignedBigInteger('attribute_id');
            $table->integer('statut')->default(1);
            $table->timestamps();

            // Add foreign key constraints
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('variation_attribute_id')->references('id')->on('variation_attributes');
            $table->foreign('attribute_id')->references('id')->on('attributes');
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('variation_attributes');
    }
};

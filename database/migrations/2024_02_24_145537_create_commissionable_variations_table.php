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
        Schema::create('commissionable_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commissionable_variation_id')->nullable();
            $table->foreign('commissionable_variation_id')->references('id')->on('commissionable_variations');

            $table->unsignedBigInteger('commissionable_id');
            $table->foreign('commissionable_id')->references('id')->on('commissionables');

            $table->unsignedBigInteger('account_user_id')->nullable();
            $table->foreign('account_user_id')->references('id')->on('account_user');

            $table->unsignedBigInteger('account_id');
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('commissionable_variations');
    }
};

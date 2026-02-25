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
        Schema::create('phone_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('phone_id');
            $table->unsignedBigInteger('phone_type_id');
            $table->integer('statut')->default(1);
            $table->unsignedBigInteger('account_id');
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('phone_id')->references('id')->on('phones');
            $table->foreign('phone_type_id')->references('id')->on('phone_types');
            $table->foreign('account_id')->references('id')->on('accounts');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('phone_type');
    }
};

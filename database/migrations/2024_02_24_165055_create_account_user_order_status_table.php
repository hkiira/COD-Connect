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
        Schema::create('account_user_order_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('order_status_id');
            $table->boolean('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('order_status_id')->references('id')->on('order_statuses');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('account_user_order_status');
    }
};

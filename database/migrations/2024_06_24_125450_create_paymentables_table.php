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
        Schema::create('paymentables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('paymentable_id');
            $table->string('paymentable_type');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('commission', 10, 2);
            $table->integer('statut');
            $table->timestamps();
            $table->softDeletes();

            // Clés étrangères
            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('paymentables');
    }
};

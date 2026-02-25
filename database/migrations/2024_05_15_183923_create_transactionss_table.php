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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->string('transaction_type');
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('transaction_type_id');
            $table->boolean('statut')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_user_id')->references('id')->on('account_user');
            $table->foreign('checkout_id')->references('id')->on('checkouts');
            $table->foreign('transaction_type_id')->references('id')->on('transaction_types');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_user_id']);
            $table->dropForeign(['checkout_id']);
            $table->dropForeign(['transaction_type_id']);
        });
        Schema::dropIfExists('transactions');
    }
};

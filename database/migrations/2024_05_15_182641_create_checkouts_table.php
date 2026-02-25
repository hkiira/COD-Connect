<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCheckoutsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->string('title');
            $table->integer('statut')->default(1);
            $table->unsignedBigInteger('account_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('checkout_id')->references('id')->on('checkouts');
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
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropForeign(['checkout_id']);
            $table->dropForeign(['account_id']);
        });
        Schema::dropIfExists('checkouts');
    }
}

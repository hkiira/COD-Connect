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
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('types_attribute_id')->nullable()->constrained();
            $table->integer('statut')->length(11)->default(1)->nullable();
            $table->bigInteger('account_user_id')->unsigned()->nullable();
            $table->foreign('account_user_id')->references('id')->on('account_user');
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
        Schema::dropIfExists('offers');
    }
};

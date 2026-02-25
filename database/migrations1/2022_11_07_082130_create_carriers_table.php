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
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained(); // new
            $table->string('title');
            $table->string('email')->nullable();
            $table->string('trackinglink')->nullable();
            $table->integer('autocode')->length(11)->default(1);
            $table->string('comment')->nullable();
            $table->integer('statut')->length(11)->nullable();

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
        Schema::dropIfExists('carriers');
    }
};

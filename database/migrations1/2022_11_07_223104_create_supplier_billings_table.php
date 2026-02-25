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
        Schema::create('supplier_billings', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('account_user_id')->constrained('account_user');
            $table->string('montant');
            $table->string('comment');
            $table->integer('statut');
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
        Schema::dropIfExists('supplier_billings');
    }
};

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
        //change name account_code
        Schema::create('account_codes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('controleur');
            $table->string('prefixe');
            $table->foreignId('account_id')->constrained();
            $table->integer('compteur')->length(11)->default(0);
            $table->integer('statut')->length(11)->default(1);
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
        Schema::dropIfExists('company_codes');
    }
};

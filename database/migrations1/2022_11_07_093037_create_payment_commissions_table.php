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
        Schema::create('payment_commissions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('account_user_id')->constrained('account_user'); //new 
            $table->foreignId('payment_method_id')->constrained();
            $table->foreignId('payment_type_id')->constrained();
            $table->double('montant',11,2)->default(0);
            $table->double('commission')->length(11)->default(1);
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
        Schema::dropIfExists('payment_commissions');
    }
};

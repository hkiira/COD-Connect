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
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_commission_id')->constrained()->nullable();
            $table->foreignId('account_user_id')->constrained('account_user')->nullable();
            $table->foreignId('charge_type_id')->constrained();
            $table->double('montant',11,2)->default(0);
            $table->string('comment')->nullable();
            $table->date('date');
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
        Schema::dropIfExists('charges');
    }
};

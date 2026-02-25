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
        Schema::create('accountables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_user_id')->constrained('account_user');
            $table->unsignedBigInteger('accountable_id');
            $table->string('accountable_type');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('accountables');
    }
};

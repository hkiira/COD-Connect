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
        Schema::create('compensationables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_compensation_id');
            $table->unsignedBigInteger('compensationable_id');
            $table->string('compensationable_type');
            $table->unsignedBigInteger('account_user_id');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->date('effective_date');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Définir les clés étrangères
            $table->foreign('account_compensation_id')->references('id')->on('account_compensation');
            $table->foreign('account_user_id')->references('id')->on('account_user');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('compensationables');
    }
};

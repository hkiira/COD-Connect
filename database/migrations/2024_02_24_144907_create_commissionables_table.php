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
        Schema::create('commissionables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('commissions');
            $table->unsignedBigInteger('commissionable_id');
            $table->string('commissionable_type');
            $table->unsignedBigInteger('account_user_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('commission', 10, 2);
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

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
        Schema::dropIfExists('commissionables');
    }
};

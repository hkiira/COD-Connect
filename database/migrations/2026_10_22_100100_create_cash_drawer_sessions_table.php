<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('cash_drawer_sessions');
        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->unsignedBigInteger('cash_drawer_id');
            $table->unsignedBigInteger('account_user_id');
            $table->decimal('opening_balance', 10, 2)->default(0);
            $table->decimal('closing_balance', 10, 2)->nullable();
            $table->decimal('expected_balance', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cash_drawer_id')->references('id')->on('cash_drawers')->onDelete('cascade');
            $table->foreign('account_user_id')->references('id')->on('account_user')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_drawer_sessions');
    }
};

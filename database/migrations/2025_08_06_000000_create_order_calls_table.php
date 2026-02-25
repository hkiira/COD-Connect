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
        Schema::create('order_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('employee_id')->constrained('account_user');
            $table->timestamp('called_at');
            $table->unsignedTinyInteger('call_number');
            $table->string('result')->nullable(); // e.g., 'answered', 'no_answer', 'postponed'
            $table->text('note')->nullable();
            $table->unsignedInteger('call_duration')->nullable(); // duration in seconds
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
        Schema::dropIfExists('order_calls');
    }
};

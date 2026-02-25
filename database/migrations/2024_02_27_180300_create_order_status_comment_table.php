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
        Schema::create('order_status_comment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_status_id')->constrained('order_statuses');
            $table->foreignId('comment_id')->constrained('comments');
            $table->tinyInteger('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_status_comment');
    }
};

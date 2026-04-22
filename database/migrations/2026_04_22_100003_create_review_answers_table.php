<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('review_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->foreignId('review_question_id')->constrained()->onDelete('cascade');
            $table->text('answer_value');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('review_answers');
    }
};

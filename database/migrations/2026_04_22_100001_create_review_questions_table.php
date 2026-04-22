<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('review_questions', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string('type')->comment('stars, multiselect, text, etc.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('review_questions');
    }
};

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
        Schema::table('attributes', function (Blueprint $table) {
            $table->json('meta')->change(); // Change column type to JSON
        });
    }

    public function down()
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->text('meta')->change(); // Revert to text if needed
        });
    }
};

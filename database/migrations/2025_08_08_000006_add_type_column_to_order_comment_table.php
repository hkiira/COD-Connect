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
        Schema::table('order_comment', function (Blueprint $table) {
            $table->enum('type', ['comment', 'auto_scoring'])
                  ->default('comment')
                  ->after('score'); // Add after score column
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_comment', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

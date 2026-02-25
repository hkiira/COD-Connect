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
            $table->tinyInteger('score')->default(0)->after('order_status_id'); // Use tinyInteger instead of unsigned to allow negative values
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
            $table->dropColumn('score');
        });
    }
};

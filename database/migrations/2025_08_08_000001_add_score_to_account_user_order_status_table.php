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
        Schema::table('account_user_order_status', function (Blueprint $table) {
            $table->tinyInteger('score')->default(0)->after('statut'); // Use tinyInteger instead of unsigned to allow negative values
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('account_user_order_status', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }
};

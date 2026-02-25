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
            $table->dropForeign(['offerable_variation_id']);
            $table->dropColumn('offerable_variation_id');
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
            $table->foreignId('offerable_variation_id')->constrained('offerable_variations');
        });
    }
};

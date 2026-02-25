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
        Schema::table('account_compensation', function (Blueprint $table) {
            $table->unsignedBigInteger('compensation_goal_id')->nullable();

            // Define the foreign key constraint
            $table->foreign('compensation_goal_id')
                ->references('id')
                ->on('compensation_goals')
                ->onDelete('set null'); // or 'cascade', 'restrict', etc. depending on your needs
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('account_compensation', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['compensation_goal_id']);

            // Then drop the column
            $table->dropColumn('compensation_goal_id');
        });
    }
};

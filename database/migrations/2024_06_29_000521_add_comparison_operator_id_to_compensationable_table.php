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
        Schema::table('compensationables', function (Blueprint $table) {
            $table->unsignedBigInteger('comparison_operator_id')->nullable();
            $table->foreign('comparison_operator_id')->references('id')->on('comparison_operators');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('compensationables', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['comparison_operator_id']);

            // Then drop the column
            $table->dropColumn('comparison_operator_id');
        });
    }
};

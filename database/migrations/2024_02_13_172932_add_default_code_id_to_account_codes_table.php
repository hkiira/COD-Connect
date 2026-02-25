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
        Schema::table('account_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('default_code_id')->nullable();
            $table->foreign('default_code_id')->references('id')->on('default_codes');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('account_codes', function (Blueprint $table) {
            $table->dropForeign(['default_code_id']);
            $table->dropColumn('default_code_id');
        });
    }
};

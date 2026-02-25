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
        Schema::table('type_taxonomies', function (Blueprint $table) {
            $table->dropForeign('type_taxonomies_account_user_id_foreign');
            $table->dropColumn('account_user_id');
            //
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('type_taxonomies', function (Blueprint $table) {
            $table->integer('account_user_id')->nullable();
            $table->foreign('account_user_id')->references('id')->on('account_user');
        });
    }
};

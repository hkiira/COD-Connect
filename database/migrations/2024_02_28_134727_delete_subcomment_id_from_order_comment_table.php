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
            $table->dropForeign('order_comments_subcomment_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            $table->dropColumn('subcomment_id');
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
            $table->unsignedBigInteger('subcomment_id');
            $table->foreign('subcomment_id')->references('id')->on('subcomments')->name('order_comments_subcomment_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
        });
    }
};

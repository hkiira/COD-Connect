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
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->unsignedBigInteger('taxonomy_id')->nullable()->after('id');
            $table->foreign('taxonomy_id')->references('id')->on('taxonomies');
       });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('taxonomies', function (Blueprint $table) {
            $table->dropForeign(['taxonomy_id']);
            $table->dropColumn('taxonomy_id');
        });
    }
};

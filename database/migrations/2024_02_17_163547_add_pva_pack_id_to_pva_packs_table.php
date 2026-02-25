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
        Schema::table('pva_packs', function (Blueprint $table) {
            
            $table->unsignedBigInteger('pva_pack_id')->nullable()->after('id');
            $table->foreign('pva_pack_id')->references('id')->on('pva_packs');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pva_packs', function (Blueprint $table) {
            $table->dropForeign(['pva_pack_id']);
            $table->dropColumn('pva_pack_id');
        });
    }
};

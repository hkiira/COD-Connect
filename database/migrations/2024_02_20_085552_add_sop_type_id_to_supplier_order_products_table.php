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
        Schema::table('supplier_order_product', function (Blueprint $table) {
            $table->unsignedBigInteger('sop_type_id')->nullable();
            $table->foreign('sop_type_id')->references('id')->on('sop_types');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('supplier_order_product', function (Blueprint $table) {
            $table->dropForeign(['sop_type_id']);
            $table->dropColumn('sop_type_id');
        });
    }
};

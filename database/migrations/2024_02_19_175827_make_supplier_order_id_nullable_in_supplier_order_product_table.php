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
            $table->unsignedBigInteger('supplier_order_id')->nullable()->change();
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
            $table->unsignedBigInteger('supplier_order_id')->nullable(false)->change();
        });
    }
};

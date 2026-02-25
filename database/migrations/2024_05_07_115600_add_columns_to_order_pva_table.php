<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToOrderPvaTable extends Migration
{
    public function up()
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->decimal('discount', 8, 2)->nullable();
            $table->decimal('initial_price', 8, 2)->nullable();
        });
    }

    public function down()
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->dropColumn('discount');
            $table->dropColumn('initial_price');
        });
    }
}

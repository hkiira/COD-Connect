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
        Schema::table('order_pva', function (Blueprint $table) {
            $table->decimal('shipping_price', 8, 2)->default(0)->after('initial_price');
            $table->boolean('principale')->default(0)->after('shipping_price');
        });
        
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->dropColumn('principale');
            $table->dropColumn('shipping_price');
        });
    }
};

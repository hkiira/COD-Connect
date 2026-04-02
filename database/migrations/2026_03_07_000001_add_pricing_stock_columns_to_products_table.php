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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->nullable()->after('title');
            $table->decimal('compare_at_price', 15, 2)->nullable()->after('cost_price');
            $table->integer('stock_quantity')->nullable()->after('compare_at_price');
            $table->integer('low_stock_threshold')->nullable()->after('stock_quantity');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'compare_at_price', 'stock_quantity', 'low_stock_threshold']);
        });
    }
};

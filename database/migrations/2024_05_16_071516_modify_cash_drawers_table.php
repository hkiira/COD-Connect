<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyCashDrawersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign('checkouts_checkout_id_foreign');
            // Then drop the column
            $table->dropColumn('checkout_id');
            
            // Add the new column and foreign key
            $table->unsignedBigInteger('cash_drawer_id')->nullable()->after('id');
            $table->foreign('cash_drawer_id')->references('id')->on('cash_drawers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            // Remove the new column and foreign key
            $table->dropForeign(['cash_drawer_id']);
            $table->dropColumn('cash_drawer_id');
            
            // Add the old column and foreign key back
            $table->unsignedBigInteger('checkout_id')->nullable()->after('id');
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('set null')->name('checkouts_checkout_id_foreign');
        });
    }
}

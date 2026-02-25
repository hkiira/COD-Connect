<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveCheckoutIdFromTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['checkout_id']);
            // Then drop the column
            $table->dropColumn('checkout_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add the column back
            $table->unsignedBigInteger('checkout_id')->nullable();
            // Add the foreign key constraint back
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('set null');
        });
    }
}

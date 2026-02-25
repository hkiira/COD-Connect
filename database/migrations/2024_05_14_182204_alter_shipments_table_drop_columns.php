<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterShipmentsTableDropColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign('transactions_transaction_type_id_foreign');
            $table->dropForeign('transactions_transaction_id_foreign');
            $table->dropColumn('transaction_type_id');
            $table->dropColumn('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_type_id')->nullable();
            $table->foreign('transaction_type_id', 'transactions_transaction_type_id_foreign')->references('id')->on('transaction_types');

            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->foreign('transaction_id', 'transactions_transaction_id_foreign')->references('id')->on('shipments');
        });
    }
}
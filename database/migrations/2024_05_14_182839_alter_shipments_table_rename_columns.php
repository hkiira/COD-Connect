<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign('transactions_account_user_id_foreign');
            $table->dropForeign('transactions_account_carrier_id_foreign');
            $table->dropColumn('account_user_id');
            $table->dropColumn('account_carrier_id');
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
            $table->foreign('account_user_id', 'transactions_account_user_id_foreign')->references('id')->on('account_user');

            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->foreign('account_carrier_id', 'transactions_account_carrier_id_foreign')->references('id')->on('account_carrier');
        });
    }
};

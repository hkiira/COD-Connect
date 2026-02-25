<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveForeignKeysFromOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('carrier_invoice_id');
            $table->dropForeign(['pickup_id']);
            $table->dropColumn('carrier_pickup_id');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('carrier_invoice_id')->nullable();
            $table->foreign('invoice_id')->references('id')->on('carrier_invoices');

            $table->unsignedBigInteger('carrier_pickup_id')->nullable();
            $table->foreign('pickup_id')->references('id')->on('carrier_pickups');
        });
    }
}

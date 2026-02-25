<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameTransactionTypesTableToShipmentTypes extends Migration
{
    public function up()
    {
        Schema::rename('transaction_types', 'shipment_types');
    }

    public function down()
    {
        Schema::rename('shipment_types', 'transaction_types');
    }
}

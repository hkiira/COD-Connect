<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameCarrierInvoicesTable extends Migration
{
    public function up()
    {
        Schema::rename('carrier_invoices', 'invoices');
    }

    public function down()
    {
        Schema::rename('invoices', 'carrier_invoices');
    }
}

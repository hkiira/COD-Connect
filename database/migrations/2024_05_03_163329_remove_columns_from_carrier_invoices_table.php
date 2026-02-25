<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveColumnsFromCarrierInvoicesTable extends Migration
{
    public function up()
    {
        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_account_carrier_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            $table->dropColumn('account_carrier_id');
            $table->dropForeign('invoices_user_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            $table->dropColumn('user_id');
            $table->dropColumn('type');
        });
    }

    public function down()
    {
        Schema::table('carrier_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('account_carrier_id');
            $table->foreign('account_carrier_id')->references('id')->on('carrier_invoices')->name('invoices_account_carrier_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->name('invoices_user_id_foreign'); // Utilisez le nom personnalisé de la clé étrangère
            $table->foreign('user_id')->references('id')->on('users');
            
            $table->string('type');
        });
    }
}

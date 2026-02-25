<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->foreign('shipment_id')->references('id')->on('shipments');

            $table->unsignedBigInteger('account_user_id')->nullable();
            $table->foreign('account_user_id')->references('id')->on('account_user');

            $table->unsignedBigInteger('carrier_id')->nullable();
            $table->foreign('carrier_id')->references('id')->on('carriers');
            
            $table->unsignedBigInteger('shipment_type_id')->nullable();
            $table->foreign('shipment_type_id')->references('id')->on('shipment_types');
        });
    }

    public function down()
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
            $table->dropColumn('shipment_id');

            $table->dropForeign(['account_user_id']);
            $table->dropColumn('account_user_id');

            $table->dropForeign(['carrier_id']);
            $table->dropColumn('carrier_id');

            $table->dropForeign(['shipment_type_id']);
            $table->dropColumn('shipment_type_id');
        });
    }
};

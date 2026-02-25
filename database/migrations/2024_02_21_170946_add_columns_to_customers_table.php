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
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('sector_id')->nullable()->after('id');
            $table->unsignedBigInteger('customer_type_id')->nullable()->after('sector_id');
            $table->string('ice')->nullable()->after('customer_type_id');
            $table->string('latitude')->nullable()->after('ice');
            $table->string('longitude')->nullable()->after('latitude');

            $table->foreign('sector_id')->references('id')->on('sectors');
            $table->foreign('customer_type_id')->references('id')->on('customer_types');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['sector_id']);
            $table->dropForeign(['customer_type_id']);

            $table->dropColumn('sector_id');
            $table->dropColumn('customer_type_id');
            $table->dropColumn('ice');
            $table->dropColumn('latitude');
            $table->dropColumn('longitude');

        });
    }
};

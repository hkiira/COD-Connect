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
        Schema::table('commissionables', function (Blueprint $table) {
            $table->unsignedBigInteger('commissionable_id')->nullable()->change();
            $table->string('commissionable_type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('commissionables', function (Blueprint $table) {
            $table->unsignedBigInteger('commissionable_id')->nullable(false)->change();
            $table->string('commissionable_type')->nullable(false)->change();
        });
    }
};

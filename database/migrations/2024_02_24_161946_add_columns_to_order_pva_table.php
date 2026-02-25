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
        Schema::table('order_pva', function (Blueprint $table) {
            $table->unsignedBigInteger('commissionable_variation_id')->nullable()->after('account_user_id');
            $table->unsignedBigInteger('offerable_variation_id')->nullable()->after('commissionable_variation_id');
        
            $table->foreign('commissionable_variation_id')->references('id')->on('commissionable_variations');
            $table->foreign('offerable_variation_id')->references('id')->on('offerable_variations');
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->unsignedBigInteger('commissionable_variation_id')->nullable()->after('account_user_id');
            $table->unsignedBigInteger('offerable_variation_id')->nullable()->after('commissionable_variation_id');
        
            $table->foreign('commissionable_variation_id')->references('id')->on('commissionable_variations');
            $table->foreign('offerable_variation_id')->references('id')->on('offerable_variations');
        
        });
    }
};

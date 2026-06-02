<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_pva', function (Blueprint $table) {
            $table->unsignedBigInteger('source_order_pva_id')->nullable()->after('product_variation_attribute_id');
            $table->foreign('source_order_pva_id')->references('id')->on('order_pva')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_pva', function (Blueprint $table) {
            //
        });
    }
};

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
        Schema::table('product_variation_attribute', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('variation_attribute_id'); // adjust 'after' as needed
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_variation_attribute', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};

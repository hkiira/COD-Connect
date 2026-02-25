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
        Schema::create('product_depot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variationAttribute_id')->constrained('product_variationAttribute');
            $table->foreignId('depot_id')->constrained();
            $table->integer('quantity')->length(11)->default(0);
            $table->string('status')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_depot');
    }
};

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
        Schema::create('pva_measurement', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->unsignedBigInteger('measurement_id');
            $table->decimal('quantity', 10, 2);
            $table->string('barcode')->nullable();
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_variation_attribute_id')->references('id')->on('product_variation_attribute');
            $table->foreign('measurement_id')->references('id')->on('measurements');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pva_measurement');
    }
};

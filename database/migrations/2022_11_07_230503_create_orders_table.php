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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code',100)->nullable(); //
            $table->foreignId('customer_id')->constrained(); // aprés la creation customer
            $table->foreignId('account_user_id')->nullable()->constrained('account_user');
            $table->foreignId('account_city_id')->constrained('account_city');
            $table->foreignId('payment_type_id')->constrained(); //new
            $table->foreignId('payment_method_id')->constrained();
            $table->foreignId('brand_source_id')->constrained('brand_source');
            $table->foreignId('pickup_id')->constrained();
            $table->foreignId('status_id')->constrained();
            $table->foreignId('payment_commission_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->string('adresse')->nullable();
            $table->double('discount',8,2)->default(0);
            $table->double('carrier_price',8,2)->default(0);
            $table->double('total',8,2)->default(0);
            $table->double('real_price',8,2)->nullable();
            $table->double('real_carrier_price',8,2)->default(0);
            $table->string('comment')->nullable();
            $table->integer('sms')->default(0);
            $table->integer('return')->nullable();
            $table->integer('affected')->nullable();
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

    }
};

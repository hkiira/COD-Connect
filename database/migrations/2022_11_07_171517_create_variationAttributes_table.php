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
Schema::create('variationAttributes', function (Blueprint $table) {
    $table->id();

    // Assuming 'account_user_id' references 'id' in 'account_user' table
    $table->unsignedBigInteger('account_user_id')->nullable();
    $table->foreign('account_user_id')->references('id')->on('account_user');

    // Assuming 'attribute_id' references 'id' in 'attributes' table
    // Replace 'attributes' with the correct table name if different
    $table->unsignedBigInteger('attribute_id')->nullable();
    $table->foreign('attribute_id')->references('id')->on('attributes');

    $table->integer('statut')->default(1);
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
        Schema::dropIfExists('VariationAttributes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropTransactionssTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('transactionss');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // If you want to reverse this operation, you could define the table structure here again.
        Schema::create('transactionss', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('account_user_id');
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->string('transaction_type');
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('transaction_type_id');
            $table->boolean('statut')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}

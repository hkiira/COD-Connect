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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->text('description');
            $table->date('date');
            $table->decimal('amount', 8, 2);
            $table->foreignId('account_user_id')->constrained('account_user');
            $table->integer('statut')->default(1);
            $table->foreignId('expense_type_id')->constrained('expense_types');
            $table->timestamps(); // This will create 'created_at' and 'updated_at' columns
            $table->softDeletes(); // This will create 'deleted_at' column
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('expenses');
    }
};

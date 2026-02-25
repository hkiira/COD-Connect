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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('commission', 10, 2)->default(0);
            $table->date('effective_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('commission_type_id')->constrained('commission_types');
            $table->foreignId('account_user_id')->constrained('account_user');
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('commissions');
    }
};

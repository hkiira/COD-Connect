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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('account_user_id')->constrained('account_user');
            $table->foreignId('inventory_type_id')->constrained('inventory_types');
            $table->foreignId('mouvement_id')->nullable()->constrained('mouvements');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->timestamps();
            $table->softDeletes();
            $table->integer('statut')->default(1);
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventories');
    }
};

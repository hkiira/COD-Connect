<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMouvementOrderPvaTable extends Migration
{
    public function up()
    {
        Schema::create('mouvement_order_pva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_pva_id')->constrained('order_pva');
            $table->foreignId('mouvement_pva_id')->constrained('mouvement_pva');
            $table->integer('statut')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mouvement_order_pva');
    }
}

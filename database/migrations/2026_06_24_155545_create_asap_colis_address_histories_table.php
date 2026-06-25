<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asap_colis_address_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asap_colis_meta_id');
            $table->string('date')->nullable();
            $table->string('client')->nullable();
            $table->string('adresse')->nullable();
            $table->string('telephone')->nullable();
            $table->timestamps();
            
            $table->foreign('asap_colis_meta_id', 'fk_asap_colis_meta_addr_id')->references('id')->on('asap_colis_metas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asap_colis_address_histories');
    }
};

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
        Schema::create('asap_colis_metas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('colis_id')->unique()->nullable();
            $table->string('code', 191)->nullable()->index();
            $table->string('destinataire')->nullable();
            $table->string('telephone')->nullable();
            $table->string('ville')->nullable();
            $table->string('adresse')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asap_colis_metas');
    }
};

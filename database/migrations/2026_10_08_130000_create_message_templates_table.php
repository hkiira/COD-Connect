<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The WhatsApp messages the agents send from the order workspaces, editable per account. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('stage', 20); // confirmation | tracking
            $table->string('title', 80);
            $table->string('language', 10); // darija | fr
            $table->text('body');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'stage', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};

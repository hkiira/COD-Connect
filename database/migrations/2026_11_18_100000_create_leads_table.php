<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50)->default('tiktok');
            $table->string('external_id', 191)->nullable();
            $table->dateTime('lead_date')->nullable();
            $table->string('name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 32)->default('new');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_data')->nullable();
            $table->string('imported_file')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'source', 'external_id'], 'leads_source_external_unique');
            $table->index(['account_id', 'status', 'lead_date'], 'leads_account_status_date_idx');
            $table->index(['account_id', 'phone'], 'leads_account_phone_idx');
        });

        Schema::create('lead_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);
            $table->char('fingerprint', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->timestamps();

            $table->unique(['account_id', 'source', 'fingerprint'], 'lead_mapping_profile_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_mappings');
        Schema::dropIfExists('leads');
    }
};

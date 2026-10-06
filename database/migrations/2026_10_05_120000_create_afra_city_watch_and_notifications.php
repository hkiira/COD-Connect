<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - afra_cities: last copy of Afra's city list, compared every day to detect changes.
 * - afra_city_changes: what changed (added / renamed / price / removed) and whether it was handled.
 * - notifications: Laravel's database notifications (the bell in the dashboard header).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afra_cities', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
            $table->unsignedBigInteger('id')->primary(); // Afra's city id
            $table->string('name');
            $table->decimal('delivery_price', 10, 2)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('afra_city_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
            $table->id();
            $table->unsignedBigInteger('afra_city_id')->index();
            $table->string('type', 16); // added | renamed | price | removed
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->string('auto_action')->nullable(); // what the check already did, e.g. linked to a local city
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('account_user');
            $table->timestamps();
        });

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('afra_city_changes');
        Schema::dropIfExists('afra_cities');
        // notifications is generic: kept.
    }
};

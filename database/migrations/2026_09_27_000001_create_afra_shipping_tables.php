<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('default_carriers', 'city_id_carrier')) {
            Schema::table('default_carriers', function (Blueprint $table) {
                $table->unsignedBigInteger('city_id_carrier')->nullable()->after('city_id');
            });
        }

        Schema::create('afra_status_mappings', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->unsignedBigInteger('afra_status_id');
            $table->foreignId('order_status_id')->constrained();
            $table->timestamps();
            $table->unique(['account_id', 'afra_status_id']);
        });

        Schema::create('afra_sync_runs', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('account_user_id')->nullable()->constrained('account_user');
            $table->foreignId('pickup_id')->nullable()->constrained();
            $table->string('kind', 16);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('synchronized')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('afra_order_operations', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // foreign keys + indexes over MyISAM's 1000 bytes
            $table->id();
            $table->foreignId('order_id')->constrained()->unique();
            $table->string('create_state', 16)->nullable();
            $table->string('return_state', 16)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afra_order_operations');
        Schema::dropIfExists('afra_sync_runs');
        Schema::dropIfExists('afra_status_mappings');
        // Keep city_id_carrier: it may predate this migration or contain live mappings.
    }
};

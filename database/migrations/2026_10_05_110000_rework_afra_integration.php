<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Afra integration rework:
 * - account_carrier.settings: per-account carrier options (Afra: test_product yes/no).
 * - afra_status_mappings.comment_id: an Afra status now maps to a status comment (reason), applied
 *   like an agent would, so the order keeps its comment / status history.
 * - afra_order_operations.create_attempted_at: lets an uncertain creation be matched later.
 * - afra_order_operations.remote_status: last Afra status applied, so a status is applied once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('account_carrier', 'settings')) {
            Schema::table('account_carrier', function (Blueprint $table) {
                $table->json('settings')->nullable()->after('token');
            });
        }

        if (Schema::hasTable('afra_status_mappings') && !Schema::hasColumn('afra_status_mappings', 'comment_id')) {
            Schema::table('afra_status_mappings', function (Blueprint $table) {
                $table->foreignId('comment_id')->nullable()->after('afra_status_id')->constrained('comments');
                $table->unsignedBigInteger('order_status_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('afra_order_operations') && !Schema::hasColumn('afra_order_operations', 'create_attempted_at')) {
            Schema::table('afra_order_operations', function (Blueprint $table) {
                $table->timestamp('create_attempted_at')->nullable()->after('create_state');
                $table->string('remote_status')->nullable()->after('return_state');
                $table->timestamp('remote_status_at')->nullable()->after('remote_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('afra_order_operations', 'create_attempted_at')) {
            Schema::table('afra_order_operations', fn (Blueprint $table) => $table->dropColumn(['create_attempted_at', 'remote_status', 'remote_status_at']));
        }
        if (Schema::hasColumn('afra_status_mappings', 'comment_id')) {
            Schema::table('afra_status_mappings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('comment_id');
            });
        }
        if (Schema::hasColumn('account_carrier', 'settings')) {
            Schema::table('account_carrier', fn (Blueprint $table) => $table->dropColumn('settings'));
        }
    }
};

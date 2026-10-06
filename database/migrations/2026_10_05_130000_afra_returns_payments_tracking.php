<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - afra_status_mappings.is_return: Afra statuses meaning "the parcel is coming back"; those orders
 *   are listed for reception at the warehouse.
 * - afra_order_operations: state of delete-order and create-exchange calls, and since when an open
 *   order is no longer found in Afra's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('afra_status_mappings', function (Blueprint $table) {
            $table->boolean('is_return')->default(false)->after('comment_id');
        });

        Schema::table('afra_order_operations', function (Blueprint $table) {
            $table->string('delete_state', 16)->nullable()->after('return_state');
            $table->string('exchange_state', 16)->nullable()->after('delete_state');
            $table->timestamp('missing_since')->nullable()->after('remote_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('afra_order_operations', fn (Blueprint $table) => $table->dropColumn(['delete_state', 'exchange_state', 'missing_since']));
        Schema::table('afra_status_mappings', fn (Blueprint $table) => $table->dropColumn('is_return'));
    }
};

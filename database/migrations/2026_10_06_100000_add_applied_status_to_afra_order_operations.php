<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * afra_order_operations.applied_status: the last Afra status whose comment was applied to the order.
 * remote_status (last status seen) and applied_status differ when a status had no mapping when it was
 * seen: once it gets one, the next sync applies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('afra_order_operations', function (Blueprint $table) {
            $table->string('applied_status')->nullable()->after('remote_status_at');
        });

        // Statuses already applied before this column existed left an "Afra: <status>" comment.
        DB::table('afra_order_operations')->whereNotNull('remote_status')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('order_comment')
                ->whereColumn('order_comment.order_id', 'afra_order_operations.order_id')
                ->whereRaw("order_comment.title = CONCAT('Afra: ', afra_order_operations.remote_status)"))
            ->update(['applied_status' => DB::raw('remote_status')]);
    }

    public function down(): void
    {
        Schema::table('afra_order_operations', fn (Blueprint $table) => $table->dropColumn('applied_status'));
    }
};

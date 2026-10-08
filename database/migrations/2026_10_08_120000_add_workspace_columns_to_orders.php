<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the order workspaces (confirmation, tracking, recovery) need on an order:
 * who owns it, when the customer must be called back (the date of a "postponed" reason),
 * and which agent has it open in focus mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('account_id')->constrained('account_user')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            $table->timestamp('callback_at')->nullable()->after('assigned_at');
            $table->foreignId('claimed_by')->nullable()->after('callback_at')->constrained('account_user')->nullOnDelete();
            $table->timestamp('claimed_until')->nullable()->after('claimed_by');

            $table->index(['account_id', 'order_status_id', 'assigned_to'], 'orders_account_status_assignee_index');
            $table->index(['account_id', 'callback_at'], 'orders_account_callback_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_account_status_assignee_index');
            $table->dropIndex('orders_account_callback_index');
            $table->dropConstrainedForeignId('claimed_by');
            $table->dropColumn('claimed_until');
            $table->dropColumn('callback_at');
            $table->dropColumn('assigned_at');
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the order screens: status filters and counts, lookup by code / shipping code
 * (search, scanner), the comment timeline and the pending-order scoring job.
 * Each one is created only if missing, so the migration can be re-run after a database restore.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'orders' => [
            'orders_account_status_created_idx' => ['account_id', 'order_status_id', 'created_at'],
            'orders_account_code_idx'           => ['account_id', 'code'],
            'orders_shipping_code_idx'          => ['shipping_code'],
            'orders_pickup_status_idx'          => ['pickup_id', 'order_status_id'],
        ],
        'order_comment' => [
            'order_comment_order_created_idx' => ['order_id', 'created_at'],
            'order_comment_order_type_idx'    => ['order_id', 'type'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
